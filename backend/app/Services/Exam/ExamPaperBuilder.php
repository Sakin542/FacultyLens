<?php

namespace App\Services\Exam;

use App\Models\Assessment;
use App\Models\AssessmentBlueprint;
use App\Models\ExamPaper;
use App\Models\ExamPaperItem;
use App\Models\GeneratedQuestion;
use App\Models\PreviousQuestion;
use App\Models\Question;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Builds exam papers from the course's question pool so they hit a blueprint's targets (or ad-hoc targets):
 * every slot asks for a question count with a Bloom level, difficulty, CO, topic, type and marks; the builder picks
 * the best-matching unused candidates, records shortfalls honestly and reports coverage against the targets.
 *
 * Pool: the course question bank (previous questions), questions of the course's other assessments, and
 * faculty-approved AI drafts. Questions are copied into the paper as snapshots so later edits do not change it.
 */
class ExamPaperBuilder
{
    public const BLOOM = ['Remember', 'Understand', 'Apply', 'Analyze', 'Evaluate', 'Create'];
    public const DIFFICULTY = ['easy', 'medium', 'hard'];

    public static function bloom(?string $level): ?string
    {
        if ($level === null || trim($level) === '') {
            return null;
        }
        $l = ucfirst(strtolower(trim($level)));

        return $l === 'Analyse' ? 'Analyze' : (in_array($l, self::BLOOM, true) ? $l : null);
    }

    public static function difficulty(?string $level): ?string
    {
        $l = strtolower(trim((string) $level));

        return in_array($l, self::DIFFICULTY, true) ? $l : null;
    }

    /**
     * Normalized candidate pool for an assessment's course.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function candidates(Assessment $assessment, array $filters = []): Collection
    {
        $courseId = $assessment->course_id;
        $pool = collect();

        if (($filters['source'] ?? null) === null || $filters['source'] === 'bank') {
            PreviousQuestion::query()->where('course_id', $courseId)->get()->each(function (PreviousQuestion $q) use ($pool) {
                $pool->push([
                    'key' => 'bank:'.$q->id, 'source_type' => 'bank', 'source_id' => $q->id,
                    'question_text' => (string) $q->question_text, 'expected_answer' => null,
                    'question_type' => $q->question_type, 'marks' => (float) $q->marks,
                    'difficulty_level' => self::difficulty($q->difficulty_level), 'cognitive_level' => self::bloom($q->cognitive_level),
                    'topic' => null, 'learning_outcome_id' => null, 'options' => null,
                    'origin' => trim(($q->source ?: 'Question bank').($q->source_year ? " {$q->source_year}" : '')),
                ]);
            });
        }

        if (($filters['source'] ?? null) === null || $filters['source'] === 'question') {
            Question::query()
                ->whereHas('assessment', fn ($q) => $q->where('course_id', $courseId))
                ->where('assessment_id', '!=', $assessment->id)
                ->with('assessment:id,title')
                ->get()->each(function (Question $q) use ($pool) {
                    $pool->push([
                        'key' => 'question:'.$q->id, 'source_type' => 'question', 'source_id' => $q->id,
                        'question_text' => (string) $q->question_text, 'expected_answer' => $q->expected_answer,
                        'question_type' => $q->question_type, 'marks' => (float) $q->marks,
                        'difficulty_level' => self::difficulty($q->difficulty_level ?: $q->ai_difficulty_level),
                        'cognitive_level' => self::bloom($q->cognitive_level ?: $q->ai_cognitive_level),
                        'topic' => is_array($q->ai_topics) ? implode(', ', array_slice($q->ai_topics, 0, 3)) : null,
                        'learning_outcome_id' => $q->learning_outcome_id, 'options' => null,
                        'origin' => $q->assessment?->title ?? 'Course assessment',
                    ]);
                });
        }

        if (($filters['source'] ?? null) === null || $filters['source'] === 'generated') {
            GeneratedQuestion::query()
                ->where('review_status', GeneratedQuestion::REVIEW_APPROVED)
                ->whereHas('request', fn ($q) => $q->where('course_id', $courseId))
                ->get()->each(function (GeneratedQuestion $q) use ($pool) {
                    $pool->push([
                        'key' => 'generated:'.$q->id, 'source_type' => 'generated', 'source_id' => $q->id,
                        'question_text' => (string) $q->question_text, 'expected_answer' => $q->expected_answer,
                        'question_type' => $q->question_type, 'marks' => (float) $q->marks,
                        'difficulty_level' => self::difficulty($q->difficulty_level), 'cognitive_level' => self::bloom($q->cognitive_level),
                        'topic' => $q->topic, 'learning_outcome_id' => $q->learning_outcome_id,
                        'options' => is_array($q->options) ? $q->options : null,
                        'origin' => 'Approved AI draft',
                    ]);
                });
        }

        // Identical texts from different sources appear once (bank first)
        $pool = $pool->unique(fn ($c) => $this->fingerprint($c['question_text']))->values();

        if (!empty($filters['cognitive_level'])) {
            $pool = $pool->where('cognitive_level', self::bloom($filters['cognitive_level']));
        }
        if (!empty($filters['difficulty_level'])) {
            $pool = $pool->where('difficulty_level', self::difficulty($filters['difficulty_level']));
        }
        if (!empty($filters['search'])) {
            $needle = mb_strtolower((string) $filters['search']);
            $pool = $pool->filter(fn ($c) => str_contains(mb_strtolower($c['question_text']), $needle));
        }

        return $pool->values();
    }

    /**
     * Turn a blueprint into slots.
     *
     * @return list<array<string, mixed>>
     */
    public function slotsFromBlueprint(AssessmentBlueprint $blueprint): array
    {
        $blueprint->loadMissing(['items.section', 'items.learningOutcome']);
        $slots = [];
        foreach ($blueprint->items->sortBy('sort_order') as $item) {
            $slots[] = [
                'section_title' => $item->section?->title,
                'count' => max(1, (int) $item->question_count),
                'marks_each' => (float) ($item->marks_each ?: ($item->question_count ? $item->total_marks / $item->question_count : 0)),
                'cognitive_level' => self::bloom($item->cognitive_level),
                'difficulty_level' => self::difficulty($item->difficulty_level),
                'learning_outcome_id' => $item->learning_outcome_id,
                'topic' => $item->topic,
                'question_type' => $item->question_type,
                'label' => trim(implode(' · ', array_filter([$item->topic, $item->learningOutcome?->code, self::bloom($item->cognitive_level), self::difficulty($item->difficulty_level)]))),
            ];
        }

        return $slots;
    }

    /**
     * Ad-hoc targets: N questions with a Bloom mix and a difficulty mix (percentages), equal marks.
     *
     * @return list<array<string, mixed>>
     */
    public function slotsFromTargets(array $targets): array
    {
        $count = (int) $targets['question_count'];
        $marksEach = isset($targets['marks_each']) ? (float) $targets['marks_each']
            : round(((float) ($targets['total_marks'] ?? $count)) / max(1, $count), 2);

        $bloom = $this->apportion($count, array_filter((array) ($targets['cognitive_mix'] ?? []), fn ($v, $k) => self::bloom($k) && $v > 0, ARRAY_FILTER_USE_BOTH));
        $diffQueue = [];
        foreach ($this->apportion($count, array_filter((array) ($targets['difficulty_mix'] ?? []), fn ($v, $k) => self::difficulty($k) && $v > 0, ARRAY_FILTER_USE_BOTH)) as $level => $n) {
            array_push($diffQueue, ...array_fill(0, $n, self::difficulty($level)));
        }

        // With a total but no per-question marks, split the total into whole marks that add up exactly;
        // the leftover marks go to the most demanding (highest Bloom) questions.
        $marksPlan = null;
        if (!isset($targets['marks_each']) && isset($targets['total_marks'])) {
            $total = (int) round((float) $targets['total_marks']);
            $marksPlan = array_fill(0, $count, intdiv($total, max(1, $count)));
            $leftover = $total - array_sum($marksPlan);
            for ($k = 0; $k < $leftover; $k++) {
                $marksPlan[$count - 1 - $k]++;
            }
        }

        $slots = [];
        $i = 0;
        $levels = $bloom ?: [null => $count];
        uksort($levels, fn ($a, $b) => array_search($a, self::BLOOM, true) <=> array_search($b, self::BLOOM, true));
        foreach ($levels as $level => $n) {
            for ($k = 0; $k < $n; $k++, $i++) {
                $slots[] = [
                    'section_title' => null,
                    'count' => 1,
                    'marks_each' => $marksPlan[$i] ?? $marksEach,
                    'cognitive_level' => $level === '' ? null : self::bloom((string) $level),
                    'difficulty_level' => $diffQueue[$i] ?? null,
                    'learning_outcome_id' => null,
                    'topic' => $targets['topic'] ?? null,
                    'question_type' => $targets['question_type'] ?? null,
                    'label' => trim(implode(' · ', array_filter([self::bloom((string) $level), $diffQueue[$i] ?? null]))),
                ];
            }
        }

        return $slots;
    }

    /**
     * Fill the slots from the pool and persist a draft paper (set A).
     */
    public function build(Assessment $assessment, array $slots, array $attributes, User $by): ExamPaper
    {
        if ($slots === []) {
            throw ValidationException::withMessages(['targets' => 'The blueprint has no items to fill. Add blueprint items or use custom targets.']);
        }

        $seed = (int) ($attributes['seed'] ?? random_int(1, 999999));
        $pool = $this->candidates($assessment)->shuffle($seed)->values();
        $used = [];
        $picked = [];
        $shortfalls = [];

        foreach ($slots as $slotIndex => $slot) {
            for ($n = 0; $n < $slot['count']; $n++) {
                $best = null;
                $bestScore = -1;
                foreach ($pool as $c) {
                    // A slot that names a Bloom level only accepts questions labelled with that level
                    if (isset($used[$c['key']]) || ($slot['cognitive_level'] && $c['cognitive_level'] !== $slot['cognitive_level'])) {
                        continue;
                    }
                    $score = $this->score($c, $slot);
                    if ($score > $bestScore) {
                        [$best, $bestScore] = [$c, $score];
                    }
                }
                if ($best === null) {
                    $shortfalls[$slotIndex] = [
                        'slot' => $slot['label'] ?: 'Slot '.($slotIndex + 1),
                        'needed' => $slot['count'],
                        'missing' => ($shortfalls[$slotIndex]['missing'] ?? 0) + 1,
                    ];
                    continue;
                }
                $used[$best['key']] = true;
                $picked[] = ['candidate' => $best, 'slot' => $slot, 'score' => $bestScore];
            }
        }

        return DB::transaction(function () use ($assessment, $attributes, $by, $seed, $picked, $shortfalls, $slots) {
            $paper = ExamPaper::query()->create([
                'assessment_id' => $assessment->id,
                'created_by' => $by->id,
                'blueprint_id' => $attributes['blueprint_id'] ?? null,
                'title' => $attributes['title'] ?? $assessment->title,
                'set_label' => 'A',
                'instructions' => $attributes['instructions'] ?? 'Answer all questions. Figures in the right margin indicate full marks.',
                'duration_minutes' => $attributes['duration_minutes'] ?? $assessment->duration_minutes,
                'status' => 'draft',
                'build_mode' => $attributes['build_mode'] ?? 'targets',
                'targets' => ['slots' => $slots, 'request' => $attributes['targets'] ?? null],
                'shortfalls' => array_values($shortfalls),
                'seed' => $seed,
            ]);

            foreach ($picked as $i => $p) {
                $c = $p['candidate'];
                $paper->items()->create([
                    'sort_order' => $i + 1,
                    'section_title' => $p['slot']['section_title'],
                    'source_type' => $c['source_type'],
                    'source_id' => $c['source_id'],
                    'question_text' => $c['question_text'],
                    'expected_answer' => $c['expected_answer'],
                    'question_type' => $c['question_type'] ?: $p['slot']['question_type'],
                    // The blueprint decides the marks for its slot
                    'marks' => $p['slot']['marks_each'] > 0 ? $p['slot']['marks_each'] : $c['marks'],
                    'difficulty_level' => $c['difficulty_level'],
                    'cognitive_level' => $c['cognitive_level'],
                    'topic' => $c['topic'] ?: $p['slot']['topic'],
                    'learning_outcome_id' => $c['learning_outcome_id'] ?: $p['slot']['learning_outcome_id'],
                    'options' => $c['options'],
                ]);
            }

            return $this->refreshTotals($paper);
        });
    }

    /** Recompute total marks and coverage after any change to the items. */
    public function refreshTotals(ExamPaper $paper): ExamPaper
    {
        $items = $paper->items()->with('learningOutcome:id,code')->get();
        $total = (float) $items->sum('marks');

        $dist = function (string $field, array $keys) use ($items, $total) {
            $out = [];
            foreach ($keys as $k) {
                $marks = (float) $items->where($field, $k)->sum('marks');
                $out[$k] = ['count' => $items->where($field, $k)->count(), 'marks' => $marks, 'percent' => $total > 0 ? round($marks / $total * 100, 1) : 0];
            }
            $unknown = $items->whereNull($field);
            if ($unknown->isNotEmpty()) {
                $marks = (float) $unknown->sum('marks');
                $out['Unclassified'] = ['count' => $unknown->count(), 'marks' => $marks, 'percent' => $total > 0 ? round($marks / $total * 100, 1) : 0];
            }

            return $out;
        };

        $byOutcome = $items->whereNotNull('learning_outcome_id')->groupBy('learning_outcome_id')->map(fn ($g) => [
            'code' => $g->first()->learningOutcome?->code,
            'count' => $g->count(),
            'marks' => (float) $g->sum('marks'),
            'percent' => $total > 0 ? round($g->sum('marks') / $total * 100, 1) : 0,
        ])->values()->all();

        $requested = collect($paper->targets['slots'] ?? []);
        $targetBloom = [];
        $targetTotal = (float) $requested->sum(fn ($s) => $s['count'] * $s['marks_each']);
        foreach ($requested as $s) {
            if ($s['cognitive_level']) {
                $targetBloom[$s['cognitive_level']] = ($targetBloom[$s['cognitive_level']] ?? 0) + $s['count'] * $s['marks_each'];
            }
        }

        $paper->forceFill([
            'total_marks' => $total,
            'coverage' => [
                'question_count' => $items->count(),
                'total_marks' => $total,
                'target_total_marks' => $targetTotal ?: null,
                'target_question_count' => (int) $requested->sum('count') ?: null,
                'bloom' => $dist('cognitive_level', self::BLOOM),
                'target_bloom_percent' => $targetTotal > 0 ? array_map(fn ($m) => round($m / $targetTotal * 100, 1), $targetBloom) : [],
                'difficulty' => $dist('difficulty_level', self::DIFFICULTY),
                'learning_outcomes' => $byOutcome,
                'answer_key_complete' => $items->every(fn ($i) => trim((string) $i->expected_answer) !== '' && !$i->answer_needs_review),
            ],
        ])->save();

        return $paper->fresh(['items.learningOutcome']);
    }

    protected function score(array $c, array $slot): float
    {
        $score = 0.0;
        if ($slot['cognitive_level'] && $c['cognitive_level'] === $slot['cognitive_level']) {
            $score += 3;
        }
        if ($slot['learning_outcome_id'] && (int) $c['learning_outcome_id'] === (int) $slot['learning_outcome_id']) {
            $score += 3;
        }
        if ($slot['difficulty_level'] && $c['difficulty_level'] === $slot['difficulty_level']) {
            $score += 2;
        }
        if (!empty($slot['topic'])) {
            $topic = mb_strtolower((string) $slot['topic']);
            if (str_contains(mb_strtolower((string) $c['topic']), $topic) || str_contains(mb_strtolower($c['question_text']), $topic)) {
                $score += 2;
            }
        }
        if (!empty($slot['question_type']) && $c['question_type'] && strtolower($c['question_type']) === strtolower((string) $slot['question_type'])) {
            $score += 1;
        }
        if ($slot['marks_each'] > 0 && $c['marks'] > 0) {
            $score += max(0, 1 - abs($c['marks'] - $slot['marks_each']) / max($slot['marks_each'], 1));
        }
        // Questions with an answer key are more useful for the answer-key export
        if (trim((string) $c['expected_answer']) !== '') {
            $score += 0.25;
        }

        return $score;
    }

    /** Largest-remainder split of $total into the given percentage weights. */
    protected function apportion(int $total, array $weights): array
    {
        $sum = array_sum($weights);
        if ($sum <= 0) {
            return [];
        }
        $result = [];
        $remainders = [];
        foreach ($weights as $key => $weight) {
            $raw = $weight / $sum * $total;
            $result[$key] = (int) floor($raw);
            $remainders[$key] = $raw - $result[$key];
        }
        arsort($remainders);
        $left = $total - array_sum($result);
        foreach (array_keys($remainders) as $key) {
            if ($left <= 0) {
                break;
            }
            $result[$key]++;
            $left--;
        }

        return array_filter($result);
    }

    protected function fingerprint(string $text): string
    {
        return sha1(preg_replace('/[^a-z0-9]+/', '', mb_strtolower($text)));
    }

    /** Snapshot a candidate (by key) into the paper as an extra item. */
    public function addCandidate(ExamPaper $paper, string $key, ?float $marks = null): ExamPaperItem
    {
        $candidate = $this->candidates($paper->assessment)->firstWhere('key', $key);
        if (!$candidate) {
            throw ValidationException::withMessages(['candidate' => 'That question is not in this course\'s question pool.']);
        }
        $item = $paper->items()->create([
            'sort_order' => (int) $paper->items()->max('sort_order') + 1,
            'source_type' => $candidate['source_type'],
            'source_id' => $candidate['source_id'],
            'question_text' => $candidate['question_text'],
            'expected_answer' => $candidate['expected_answer'],
            'question_type' => $candidate['question_type'],
            'marks' => $marks ?? ($candidate['marks'] ?: 1),
            'difficulty_level' => $candidate['difficulty_level'],
            'cognitive_level' => $candidate['cognitive_level'],
            'topic' => $candidate['topic'],
            'learning_outcome_id' => $candidate['learning_outcome_id'],
            'options' => $candidate['options'],
        ]);
        $this->refreshTotals($paper);

        return $item;
    }
}
