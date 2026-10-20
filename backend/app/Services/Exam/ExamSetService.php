<?php

namespace App\Services\Exam;

use App\Models\ExamPaper;
use App\Models\Question;
use App\Models\User;
use App\Services\AiService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Exam sets B/C/D from set A: question order is shuffled (within each section) and, optionally, every question is
 * replaced by an AI variant with changed numbers and/or wording. Variant answer keys that may have changed are flagged.
 */
class ExamSetService
{
    public function __construct(protected AiService $ai, protected ExamPaperBuilder $builder) {}

    /**
     * @return array{sets: list<ExamPaper>, warnings: list<string>}
     */
    public function createSets(ExamPaper $base, int $count, string $vary, User $by): array
    {
        if ($base->parent_paper_id) {
            throw ValidationException::withMessages(['paper' => 'Create sets from set A.']);
        }
        $base->loadMissing('items');
        if ($base->items->isEmpty()) {
            throw ValidationException::withMessages(['paper' => 'Add questions to set A before creating more sets.']);
        }

        $existing = $base->sets()->pluck('set_label')->all();
        $labels = array_values(array_diff(['B', 'C', 'D', 'E'], $existing));
        if ($count > count($labels)) {
            throw ValidationException::withMessages(['count' => 'A paper can have at most five sets (A–E).']);
        }

        $warnings = [];
        $sets = [];
        foreach (array_slice($labels, 0, $count) as $n => $label) {
            $setSeed = $base->seed * 31 + ord($label);
            $order = $this->shuffledOrder($base, $setSeed);

            $set = DB::transaction(function () use ($base, $label, $vary, $setSeed, $order, $by, &$warnings) {
                $set = ExamPaper::query()->create([
                    'assessment_id' => $base->assessment_id,
                    'created_by' => $by->id,
                    'blueprint_id' => $base->blueprint_id,
                    'parent_paper_id' => $base->id,
                    'title' => $base->title,
                    'set_label' => $label,
                    'instructions' => $base->instructions,
                    'duration_minutes' => $base->duration_minutes,
                    'status' => 'draft',
                    'build_mode' => 'set',
                    'targets' => $base->targets,
                    'shortfalls' => $base->shortfalls,
                    'seed' => $setSeed,
                ]);

                foreach ($order as $position => $item) {
                    $text = $item->question_text;
                    $answer = $item->expected_answer;
                    $meta = null;
                    $needsReview = $item->answer_needs_review;

                    if ($vary !== 'shuffle') {
                        try {
                            $res = $this->ai->generateQuestionVariants([
                                'question_text' => $item->question_text,
                                'expected_answer' => $item->expected_answer,
                                'count' => 1,
                                'mode' => $vary,
                                'seed' => $setSeed + $item->id,
                            ]);
                            $variant = $res['variants'][0] ?? null;
                            if ($variant) {
                                $text = $variant['question_text'];
                                $answer = $variant['expected_answer'] ?? $answer;
                                $needsReview = $needsReview || (bool) ($variant['answer_needs_review'] ?? false);
                                $meta = [
                                    'methods' => $variant['methods'] ?? [],
                                    'number_changes' => $variant['number_changes'] ?? [],
                                    'similarity' => $variant['similarity'] ?? null,
                                    'engine' => $res['engine'] ?? null,
                                ];
                            } else {
                                $warnings[] = "Set {$label}, question {$position}: no distinct variant could be made; the original was kept.";
                            }
                        } catch (Throwable $e) {
                            Log::warning('Exam set variant failed: '.$e->getMessage());
                            $warnings[] = "Set {$label}, question {$position}: the AI service was unavailable; the original wording was kept.";
                        }
                    }

                    $set->items()->create([
                        'sort_order' => $position,
                        'section_title' => $item->section_title,
                        'source_type' => $item->source_type,
                        'source_id' => $item->source_id,
                        'question_text' => $text,
                        'expected_answer' => $answer,
                        'question_type' => $item->question_type,
                        'marks' => $item->marks,
                        'difficulty_level' => $item->difficulty_level,
                        'cognitive_level' => $item->cognitive_level,
                        'topic' => $item->topic,
                        'learning_outcome_id' => $item->learning_outcome_id,
                        // MCQ options are shuffled per set, unless the key is a bare letter ("B") that would stop matching
                        'options' => preg_match('/^\s*\(?[A-Ha-h]\)?\s*$/', (string) $answer)
                            ? $item->options
                            : $this->shuffleOptions($item->options, $setSeed + $item->id),
                        'variant_of_item_id' => $item->id,
                        'variant_meta' => $meta,
                        'answer_needs_review' => $needsReview,
                    ]);
                }

                return $set;
            });

            $sets[] = $this->builder->refreshTotals($set);
        }

        return ['sets' => $sets, 'warnings' => array_values(array_unique($warnings))];
    }

    /**
     * Copy the paper's questions into the assessment as official questions (appended after existing ones).
     */
    public function applyToAssessment(ExamPaper $paper): int
    {
        $paper->loadMissing('items', 'assessment');

        return DB::transaction(function () use ($paper) {
            $next = (int) Question::query()->where('assessment_id', $paper->assessment_id)->count();
            foreach ($paper->items as $item) {
                $next++;
                Question::query()->create([
                    'assessment_id' => $paper->assessment_id,
                    'question_number' => (string) $next,
                    'question_text' => $item->question_text,
                    'question_type' => $item->question_type ?: 'descriptive',
                    'marks' => $item->marks,
                    'difficulty_level' => $item->difficulty_level,
                    'cognitive_level' => $item->cognitive_level,
                    'learning_outcome_id' => $item->learning_outcome_id,
                    'expected_answer' => $item->expected_answer,
                ]);
            }
            $paper->forceFill(['applied_at' => now()])->save();

            return $paper->items->count();
        });
    }

    /** @return list<\App\Models\ExamPaperItem> new order, sections kept in their original order */
    protected function shuffledOrder(ExamPaper $base, int $seed): array
    {
        $ordered = [];
        $base->items->groupBy(fn ($i) => (string) $i->section_title)->each(function ($group) use (&$ordered, $seed) {
            foreach ($group->shuffle($seed)->values() as $item) {
                $ordered[count($ordered) + 1] = $item;
            }
        });

        return $ordered;
    }

    protected function shuffleOptions(?array $options, int $seed): ?array
    {
        if (!$options || !array_is_list($options) || count($options) < 2) {
            return $options;
        }

        return collect($options)->shuffle($seed)->values()->all();
    }
}
