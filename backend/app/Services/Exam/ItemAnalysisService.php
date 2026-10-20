<?php

namespace App\Services\Exam;

use App\Models\Assessment;
use App\Models\Question;
use App\Models\StudentAnswer;
use App\Models\StudentSubmission;

/**
 * Classical test theory item analysis from awarded marks:
 *
 *   difficulty index p   mean proportion of the item's marks earned (0 = nobody, 1 = everyone)
 *   discrimination D     p(upper 27% by total score) − p(lower 27%)
 *   item–rest r          Pearson correlation between the item score and the total WITHOUT that item
 *   Cronbach's alpha     internal consistency of the whole paper (works for partial-credit items)
 *
 * Only fully graded submissions are used: an answer with no awarded marks excludes its submission, while an
 * unanswered question scores 0. No student identity leaves this service.
 */
class ItemAnalysisService
{
    public const MIN_SUBMISSIONS = 5;
    public const GROUP_FRACTION = 0.27;

    public function analyze(Assessment $assessment): array
    {
        $questions = Question::query()->where('assessment_id', $assessment->id)->orderBy('id')->get(['id', 'question_number', 'question_text', 'marks', 'cognitive_level', 'difficulty_level']);
        $questions = $questions->filter(fn ($q) => (float) $q->marks > 0)->values();

        $submissions = StudentSubmission::query()->where('assessment_id', $assessment->id)->pluck('id');
        $answers = StudentAnswer::query()->whereIn('student_submission_id', $submissions)->whereIn('question_id', $questions->pluck('id'))
            ->get(['student_submission_id', 'question_id', 'awarded_marks'])
            ->groupBy('student_submission_id');

        $matrix = [];   // submission => [question_id => fraction]
        $raw = [];      // submission => [question_id => marks]
        $excluded = 0;
        foreach ($submissions as $sid) {
            $rows = $answers->get($sid, collect())->keyBy('question_id');
            if ($rows->isEmpty()) {
                continue; // nothing submitted
            }
            if ($rows->contains(fn ($a) => $a->awarded_marks === null)) {
                $excluded++;
                continue;
            }
            foreach ($questions as $q) {
                $marks = (float) ($rows->get($q->id)?->awarded_marks ?? 0);
                $raw[$sid][$q->id] = $marks;
                $matrix[$sid][$q->id] = max(0.0, min(1.0, $marks / (float) $q->marks));
            }
        }

        $n = count($matrix);
        $base = [
            'assessment' => ['id' => $assessment->id, 'title' => $assessment->title],
            'submissions_analyzed' => $n,
            'submissions_excluded_ungraded' => $excluded,
            'minimum_required' => self::MIN_SUBMISSIONS,
            'group_fraction' => self::GROUP_FRACTION,
        ];
        if ($n < self::MIN_SUBMISSIONS || $questions->isEmpty()) {
            return $base + [
                'sufficient' => false,
                'message' => $questions->isEmpty()
                    ? 'This assessment has no questions with marks.'
                    : 'Item analysis needs at least '.self::MIN_SUBMISSIONS." fully graded submissions (found {$n}).",
                'items' => [],
                'summary' => null,
            ];
        }

        // Rank by total marks; upper and lower groups of 27% (at least one submission each)
        $totals = array_map(fn ($row) => array_sum($row), $raw);
        arsort($totals);
        $ranked = array_keys($totals);
        $g = max(1, (int) round($n * self::GROUP_FRACTION));
        $upper = array_slice($ranked, 0, $g);
        $lower = array_slice($ranked, -$g);

        $items = [];
        $itemVariances = [];
        foreach ($questions as $q) {
            $scores = array_map(fn ($sid) => $matrix[$sid][$q->id], $ranked);
            $p = array_sum($scores) / $n;
            $pu = array_sum(array_map(fn ($sid) => $matrix[$sid][$q->id], $upper)) / $g;
            $pl = array_sum(array_map(fn ($sid) => $matrix[$sid][$q->id], $lower)) / $g;
            $d = $pu - $pl;

            $itemMarks = array_map(fn ($sid) => $raw[$sid][$q->id], $ranked);
            $rest = array_map(fn ($sid) => $totals[$sid] - $raw[$sid][$q->id], $ranked);
            $r = $this->pearson($itemMarks, $rest);
            $itemVariances[] = $this->variance($itemMarks);

            $flags = [];
            if ($this->variance($itemMarks) == 0.0) {
                $flags[] = 'no_variance';
            }
            if ($p > 0.90) {
                $flags[] = 'too_easy';
            } elseif ($p < 0.20) {
                $flags[] = 'too_hard';
            }
            if ($d < 0) {
                $flags[] = 'negative_discrimination';
            } elseif ($d < 0.20 && !in_array('no_variance', $flags, true)) {
                $flags[] = 'poor_discrimination';
            }

            $items[] = [
                'question_id' => $q->id,
                'question_number' => $q->question_number,
                'question_excerpt' => mb_strimwidth((string) $q->question_text, 0, 140, '…'),
                'max_marks' => (float) $q->marks,
                'cognitive_level' => $q->cognitive_level,
                'labelled_difficulty' => $q->difficulty_level,
                'mean_marks' => round(array_sum($itemMarks) / $n, 2),
                'difficulty_index' => round($p, 3),
                'difficulty_band' => $p > 0.80 ? 'easy' : ($p < 0.30 ? 'hard' : 'moderate'),
                'upper_p' => round($pu, 3),
                'lower_p' => round($pl, 3),
                'discrimination_index' => round($d, 3),
                'discrimination_band' => $d >= 0.40 ? 'excellent' : ($d >= 0.30 ? 'good' : ($d >= 0.20 ? 'marginal' : 'poor')),
                'item_rest_correlation' => $r === null ? null : round($r, 3),
                'flags' => $flags,
                'recommendation' => $this->recommendation($flags, $p, $d, $q->difficulty_level),
            ];
        }

        $k = count($items);
        $totalVariance = $this->variance(array_values($totals));
        $alpha = ($k > 1 && $totalVariance > 0) ? ($k / ($k - 1)) * (1 - array_sum($itemVariances) / $totalVariance) : null;
        $maxTotal = (float) $questions->sum('marks');

        return $base + [
            'sufficient' => true,
            'message' => null,
            'items' => $items,
            'summary' => [
                'questions' => $k,
                'mean_total' => round(array_sum($totals) / $n, 2),
                'max_total' => $maxTotal,
                'mean_percent' => $maxTotal > 0 ? round(array_sum($totals) / $n / $maxTotal * 100, 1) : null,
                'std_dev_total' => round(sqrt($totalVariance), 2),
                'cronbach_alpha' => $alpha === null ? null : round($alpha, 3),
                'reliability_band' => $alpha === null ? null : ($alpha >= 0.8 ? 'good' : ($alpha >= 0.7 ? 'acceptable' : ($alpha >= 0.6 ? 'questionable' : 'poor'))),
                'flagged_items' => count(array_filter($items, fn ($i) => $i['flags'] !== [])),
                'mean_difficulty_index' => round(array_sum(array_column($items, 'difficulty_index')) / $k, 3),
                'mean_discrimination_index' => round(array_sum(array_column($items, 'discrimination_index')) / $k, 3),
            ],
        ];
    }

    protected function recommendation(array $flags, float $p, float $d, ?string $labelled): string
    {
        if (in_array('negative_discrimination', $flags, true)) {
            return 'Weaker students scored better than stronger ones. Check the answer key and the wording for ambiguity before reusing this question.';
        }
        if (in_array('no_variance', $flags, true)) {
            return 'Every student scored the same, so this question does not distinguish ability. Consider revising or replacing it.';
        }
        if (in_array('too_hard', $flags, true)) {
            return 'Very few students earned these marks. Check whether the topic was covered, whether the question is clear, and whether the marks are reasonable.';
        }
        if (in_array('too_easy', $flags, true)) {
            return 'Almost everyone earned full marks. Fine as a warm-up; for ranking students, raise the cognitive demand.';
        }
        if (in_array('poor_discrimination', $flags, true)) {
            return 'Stronger and weaker students scored similarly. Revise the question or the rubric so it separates levels of understanding.';
        }
        $note = $d >= 0.40 ? 'Separates stronger and weaker students very well. A good question to keep in the bank.' : 'Works as intended.';
        $actual = $p > 0.80 ? 'easy' : ($p < 0.30 ? 'hard' : 'medium');
        if ($labelled && strtolower($labelled) !== $actual && !($actual === 'medium' && strtolower($labelled) === 'moderate')) {
            $note .= " It was labelled {$labelled} but performed as {$actual}; consider updating the label.";
        }

        return $note;
    }

    protected function variance(array $xs): float
    {
        $n = count($xs);
        if ($n < 2) {
            return 0.0;
        }
        $mean = array_sum($xs) / $n;

        return array_sum(array_map(fn ($x) => ($x - $mean) ** 2, $xs)) / ($n - 1);
    }

    protected function pearson(array $xs, array $ys): ?float
    {
        $n = count($xs);
        if ($n < 3) {
            return null;
        }
        $mx = array_sum($xs) / $n;
        $my = array_sum($ys) / $n;
        $num = $sx = $sy = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $num += ($xs[$i] - $mx) * ($ys[$i] - $my);
            $sx += ($xs[$i] - $mx) ** 2;
            $sy += ($ys[$i] - $my) ** 2;
        }

        return ($sx > 0 && $sy > 0) ? $num / sqrt($sx * $sy) : null;
    }
}
