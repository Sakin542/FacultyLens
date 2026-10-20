<?php

namespace App\Services;

use App\Models\AiGradingResult;
use App\Models\Assessment;
use App\Models\Question;

/**
 * Plain-language class summary after grading: how the class did and what it struggled with.
 *
 * Built only from recorded results, so every sentence can be traced back:
 * - marks: FINALIZED faculty marks (the same answers the performance analysis uses), never AI suggestions;
 * - rubric detail: the per-criterion evidence that AI grading assistance recorded for those answers (which criteria
 *   were not addressed, and which expected points were missing). Results the faculty member rejected are left out.
 *
 * The note is assembled from templates, not generated, so it cannot state anything the data does not show.
 * It describes the class as a whole and names no student.
 */
class ClassPerformanceSummaryService
{
    public const MIN_RESPONSES_PER_CRITERION = 3;
    public const STRUGGLE_SHARE = 0.4;
    public const STRENGTH_SHARE = 0.7;

    public function __construct(protected StudentPerformanceService $performance) {}

    public function summarize(Assessment $assessment): array
    {
        $expected = $this->performance->expected();
        $answers = $this->performance->finalizedAnswersQuery($assessment)
            ->get(['student_answers.id as id', 'student_answers.question_id', 'student_answers.awarded_marks', 'student_submissions.student_id']);

        if ($answers->isEmpty()) {
            return [
                'status' => 'no_data',
                'message' => 'No finalized grades yet. Finalize marks for some answers, then generate the summary.',
                'paragraphs' => [],
                'note' => '',
            ];
        }

        $questions = Question::query()->where('assessment_id', $assessment->id)->orderBy('question_number')->orderBy('id')
            ->get(['id', 'question_number', 'question_text', 'marks'])->keyBy('id');

        // ------------------------------------------------------------ marks per question
        $perQuestion = [];
        $sumAwarded = 0.0;
        $sumMax = 0.0;
        foreach ($answers->groupBy('question_id') as $questionId => $rows) {
            $q = $questions->get($questionId);
            if (!$q || (float) $q->marks <= 0) {
                continue;
            }
            $awarded = (float) $rows->sum('awarded_marks');
            $max = (float) $q->marks * $rows->count();
            $sumAwarded += $awarded;
            $sumMax += $max;
            $perQuestion[] = [
                'question_id' => $q->id,
                'number' => $this->label($q),
                'text' => mb_strimwidth((string) $q->question_text, 0, 160, '…'),
                'responses' => $rows->count(),
                'average' => round(100 * $awarded / $max, 1),
            ];
        }
        $average = $sumMax > 0 ? round(100 * $sumAwarded / $sumMax, 1) : null;

        // ------------------------------------------------------------ rubric evidence per criterion
        $results = AiGradingResult::query()->current()
            ->whereIn('student_answer_id', $answers->pluck('id'))
            ->where(fn ($q) => $q->whereNull('faculty_decision')->orWhere('faculty_decision', '!=', AiGradingResult::DECISION_REJECTED))
            ->whereIn('grading_status', [AiGradingResult::STATUS_COMPLETED, AiGradingResult::STATUS_REVIEWED, AiGradingResult::STATUS_FINALIZED])
            ->with('criterionResults')
            ->get(['id', 'student_answer_id', 'question_id']);

        $criteria = [];
        foreach ($results as $result) {
            foreach ($result->criterionResults as $c) {
                $key = $result->question_id.'|'.mb_strtolower(trim((string) $c->criterion));
                $criteria[$key] ??= [
                    'question_id' => $result->question_id,
                    'criterion' => (string) $c->criterion,
                    'responses' => 0,
                    'levels' => ['STRONG' => 0, 'PARTIAL' => 0, 'LIMITED' => 0, 'NOT_ADDRESSED' => 0],
                    'missing' => [],
                ];
                $entry = &$criteria[$key];
                $entry['responses']++;
                $level = strtoupper((string) $c->coverage_level);
                if (isset($entry['levels'][$level])) {
                    $entry['levels'][$level]++;
                }
                foreach (array_unique(array_map(fn ($m) => $this->cleanMissing((string) $m), (array) ($c->missing_elements ?? []))) as $missing) {
                    if ($missing !== '') {
                        $entry['missing'][$missing] = ($entry['missing'][$missing] ?? 0) + 1;
                    }
                }
                unset($entry);
            }
        }

        $struggles = [];
        $strengths = [];
        foreach ($criteria as $c) {
            if ($c['responses'] < self::MIN_RESPONSES_PER_CRITERION) {
                continue;
            }
            arsort($c['missing']);
            $weak = $c['levels']['NOT_ADDRESSED'] + $c['levels']['LIMITED'];
            $row = [
                'question_id' => $c['question_id'],
                'question_number' => $this->label($questions->get($c['question_id'])),
                'criterion' => $c['criterion'],
                'responses' => $c['responses'],
                'levels' => $c['levels'],
                'weak' => $weak,
                'top_missing' => array_map(fn ($text, $count) => ['text' => $text, 'count' => $count], array_slice(array_keys($c['missing']), 0, 3), array_slice(array_values($c['missing']), 0, 3)),
            ];
            if ($weak / $c['responses'] >= self::STRUGGLE_SHARE) {
                $struggles[] = $row;
            } elseif ($c['levels']['STRONG'] / $c['responses'] >= self::STRENGTH_SHARE) {
                $strengths[] = $row;
            }
        }
        usort($struggles, fn ($a, $b) => [$b['weak'] / $b['responses'], $b['responses']] <=> [$a['weak'] / $a['responses'], $a['responses']]);
        usort($strengths, fn ($a, $b) => $b['levels']['STRONG'] / $b['responses'] <=> $a['levels']['STRONG'] / $a['responses']);
        $struggles = array_slice($struggles, 0, 5);
        $strengths = array_slice($strengths, 0, 3);

        $rubricAnswers = $results->filter(fn ($r) => $r->criterionResults->isNotEmpty())->count();
        $stats = [
            'answers' => $answers->count(),
            'students' => $answers->pluck('student_id')->unique()->count(),
            'questions' => count($perQuestion),
            'average' => $average,
            'expected' => $expected,
            'rubric_answers' => $rubricAnswers,
        ];
        $paragraphs = $this->paragraphs($stats, $perQuestion, $struggles, $strengths);

        return [
            'status' => 'ready',
            'stats' => $stats,
            'questions' => $perQuestion,
            'struggles' => $struggles,
            'strengths' => $strengths,
            'paragraphs' => $paragraphs,
            'note' => implode("\n\n", $paragraphs),
            'method' => 'Finalized faculty marks, plus the rubric evidence recorded by AI grading assistance (results you rejected are excluded). The wording is built from templates, so it only states what the data shows.',
            'generated_at' => now()->toIso8601String(),
        ];
    }

    // ------------------------------------------------------------------ wording

    protected function paragraphs(array $stats, array $questions, array $struggles, array $strengths): array
    {
        $p = [];
        $avg = $stats['average'];
        $overview = sprintf(
            '%d graded answer%s from %d student%s across %d question%s.',
            $stats['answers'], $stats['answers'] === 1 ? '' : 's', $stats['students'], $stats['students'] === 1 ? '' : 's',
            $stats['questions'], $stats['questions'] === 1 ? '' : 's',
        );
        if ($avg !== null) {
            $overview .= sprintf(' The class average was %s%% against an expected %s%%', $this->pct($avg), $this->pct($stats['expected']))
                .($avg >= $stats['expected'] ? ', so overall the class met expectations.' : '.');
        }
        $p[] = $overview;

        $below = array_values(array_filter($questions, fn ($q) => $q['average'] < $stats['expected']));
        usort($below, fn ($a, $b) => $a['average'] <=> $b['average']);
        if ($below) {
            $parts = array_map(fn ($q) => sprintf('%s (%s%%)', $q['number'], $this->pct($q['average'])), array_slice($below, 0, 3));
            $p[] = 'The weakest results were on '.$this->join($parts).'.';
        } elseif ($questions) {
            $p[] = 'Every question averaged at or above the expected level.';
        }

        if ($struggles) {
            $lines = [];
            foreach (array_slice($struggles, 0, 3) as $s) {
                $line = sprintf('On %s, "%s" was missing or barely addressed in %d of %d answers', $s['question_number'], $s['criterion'], $s['weak'], $s['responses']);
                if ($s['top_missing']) {
                    $m = $s['top_missing'][0];
                    $line .= sprintf('; the point most often left out was "%s" (%d answer%s)', $m['text'], $m['count'], $m['count'] === 1 ? '' : 's');
                }
                $lines[] = $line.'.';
            }
            $p[] = 'What the class struggled with: '.implode(' ', $lines);
        }

        if ($strengths) {
            $parts = array_map(fn ($s) => sprintf('"%s" on %s (%d of %d answers addressed it well)', $s['criterion'], $s['question_number'], $s['levels']['STRONG'], $s['responses']), $strengths);
            $p[] = 'The class handled '.$this->join($parts).'.';
        }

        $topics = [];
        foreach ($struggles as $s) {
            foreach ($s['top_missing'] as $m) {
                if (count($topics) < 4 && !in_array($m['text'], $topics, true)) {
                    $topics[] = $m['text'];
                }
            }
        }
        if ($topics) {
            $p[] = 'Worth revisiting in class: '.implode('; ', $topics).'.';
        }

        if ($stats['rubric_answers'] === 0) {
            $p[] = 'No rubric detail is available yet: run AI grading assistance on answers to see which rubric points the class missed.';
        } elseif ($stats['rubric_answers'] < $stats['answers']) {
            $p[] = sprintf('Rubric detail covers %d of the %d graded answers (those with AI grading assistance).', $stats['rubric_answers'], $stats['answers']);
        }
        if ($stats['answers'] < $this->performance->minResponses()) {
            $p[] = 'Only a few answers are graded so far, so treat these patterns as early signals.';
        }

        return $p;
    }

    protected function cleanMissing(string $text): string
    {
        $text = trim(str_replace(' (only partially evident)', '', $text));
        $text = preg_replace('/^No clear discussion of:\s*/i', '', $text) ?? $text;
        $text = preg_replace('/^(?:(?:the\s+)?(?:student|answer)\s+)?(?:(?:correctly|clearly|accurately|briefly)\s+)?(?:mentions?|explains?|describes?|states?|identifies?|gives?|provides?|uses?|includes?|defines?|lists?|shows?|discusses?|notes?)\s+(?:that\s+)?/i', '', $text) ?? $text;

        return mb_strimwidth(rtrim($text, '.'), 0, 160, '…');
    }

    protected function label(?Question $q): string
    {
        return $q ? 'Q'.($q->question_number ?: $q->id) : 'a question';
    }

    protected function pct(float $v): string
    {
        return rtrim(rtrim(number_format($v, 1), '0'), '.');
    }

    protected function join(array $parts): string
    {
        if (count($parts) <= 1) {
            return implode('', $parts);
        }

        return implode(', ', array_slice($parts, 0, -1)).' and '.end($parts);
    }
}
