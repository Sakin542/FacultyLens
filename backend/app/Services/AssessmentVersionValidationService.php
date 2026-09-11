<?php

namespace App\Services;

use App\Models\AssessmentVersion;
use App\Models\LearningOutcome;
use App\Models\ProgramOutcome;

/**
 * STEP 38: deterministic validation of an assessment version snapshot (no AI).
 * Errors block approval/finalization; warnings are advisory. Nothing is fixed automatically.
 */
class AssessmentVersionValidationService
{
    public const VALID = 'VALID';
    public const VALID_WITH_WARNINGS = 'VALID_WITH_WARNINGS';
    public const INVALID = 'INVALID';

    /** Structural / metadata / mapping checks that apply at any stage. */
    public function validateVersion(AssessmentVersion $version): array
    {
        $cfg = config('assessment_versioning');
        $version->loadMissing(['questions', 'assessment.course']);
        $course = $version->assessment->course;
        $errors = [];
        $warnings = [];
        $checks = [];
        $add = function (array &$bucket, string $code, string $message, array $ctx = []) {
            $bucket[] = ['code' => $code, 'message' => $message] + $ctx;
        };

        // Required metadata
        if (trim((string) $version->title) === '') {
            $add($errors, 'TITLE_REQUIRED', 'The version needs a title.');
        }
        if (!in_array(strtolower((string) $version->assessment_type), $cfg['assessment_types'], true)) {
            $add($errors, 'ASSESSMENT_TYPE_INVALID', 'Unknown assessment type "' . $version->assessment_type . '".');
        }
        if ($version->version_number > 1 && $cfg['require_change_summary'] && trim((string) $version->change_summary) === '') {
            $add($warnings, 'CHANGE_SUMMARY_MISSING', 'Add a change summary describing what changed in this version.');
        }
        $checks['metadata'] = empty(array_filter($errors, fn ($e) => in_array($e['code'], ['TITLE_REQUIRED', 'ASSESSMENT_TYPE_INVALID'], true)));

        // Questions
        $questions = $version->questions;
        $n = $questions->count();
        if ($n === 0) {
            $add($errors, 'NO_QUESTIONS', 'The version has no questions.');
        }
        if ($n > (int) $cfg['max_questions']) {
            $add($errors, 'TOO_MANY_QUESTIONS', "A version may contain at most {$cfg['max_questions']} questions.");
        }
        $numbers = [];
        $loIds = $course ? LearningOutcome::where('course_id', $course->id)->pluck('id')->map(fn ($i) => (int) $i)->all() : [];
        $poIds = $course?->program_id ? ProgramOutcome::where('program_id', $course->program_id)->pluck('id')->map(fn ($i) => (int) $i)->all() : [];
        $missingLo = 0;
        $missingPo = 0;
        $missingDifficulty = 0;
        $missingCognitive = 0;
        foreach ($questions as $q) {
            $ctx = ['question_id' => $q->id, 'question_number' => $q->question_number];
            if (trim((string) $q->question_text) === '') {
                $add($errors, 'QUESTION_TEXT_EMPTY', "Q{$q->question_number} has no text.", $ctx);
            }
            if ((float) $q->marks <= 0) {
                $add($errors, 'QUESTION_MARKS_INVALID', "Q{$q->question_number} must carry more than 0 marks.", $ctx);
            }
            if (!in_array($q->question_type, $cfg['question_types'], true)) {
                $add($errors, 'QUESTION_TYPE_INVALID', "Q{$q->question_number} has an unknown question type \"{$q->question_type}\".", $ctx);
            }
            if ($q->difficulty_level !== null && !in_array($q->difficulty_level, $cfg['difficulty_levels'], true)) {
                $add($errors, 'DIFFICULTY_INVALID', "Q{$q->question_number} has an unknown difficulty \"{$q->difficulty_level}\".", $ctx);
            }
            if ($q->cognitive_level !== null && !in_array($q->cognitive_level, $cfg['cognitive_levels'], true)) {
                $add($errors, 'COGNITIVE_LEVEL_INVALID', "Q{$q->question_number} has an unknown cognitive level \"{$q->cognitive_level}\".", $ctx);
            }
            if (isset($numbers[$q->question_number])) {
                $add($errors, 'DUPLICATE_QUESTION_NUMBER', "Question number {$q->question_number} is used more than once.", $ctx);
            }
            $numbers[$q->question_number] = true;
            if ($q->learning_outcome_id !== null && !in_array((int) $q->learning_outcome_id, $loIds, true)) {
                $add($errors, 'LO_OUTSIDE_COURSE', "Q{$q->question_number} is mapped to a learning outcome of another course.", $ctx);
            }
            if ($q->program_outcome_id !== null && !in_array((int) $q->program_outcome_id, $poIds, true)) {
                $add($errors, 'PO_OUTSIDE_PROGRAM', "Q{$q->question_number} is mapped to a program outcome outside this course's program.", $ctx);
            }
            $missingLo += $q->learning_outcome_id === null ? 1 : 0;
            $missingPo += $q->program_outcome_id === null ? 1 : 0;
            $missingDifficulty += $q->difficulty_level === null ? 1 : 0;
            $missingCognitive += $q->cognitive_level === null ? 1 : 0;
        }
        if ($n > 0 && $missingLo > 0) {
            if ($cfg['require_learning_outcome_mapping']) {
                $add($errors, 'LO_MAPPING_MISSING', "{$missingLo} of {$n} questions have no CO/LO mapping.", ['count' => $missingLo]);
            } else {
                $add($warnings, 'LO_MAPPING_MISSING', "{$missingLo} of {$n} questions have no CO/LO mapping.", ['count' => $missingLo]);
            }
        }
        if ($n > 0 && $poIds !== [] && $missingPo > 0) {
            if ($cfg['require_program_outcome_mapping']) {
                $add($errors, 'PO_MAPPING_MISSING', "{$missingPo} of {$n} questions have no PO mapping.", ['count' => $missingPo]);
            } else {
                $add($warnings, 'PO_MAPPING_MISSING', "{$missingPo} of {$n} questions have no PO mapping.", ['count' => $missingPo]);
            }
        }
        if ($n > 0 && $missingDifficulty > 0) {
            $add($warnings, 'DIFFICULTY_MISSING', "{$missingDifficulty} of {$n} questions have no difficulty level.", ['count' => $missingDifficulty]);
        }
        if ($n > 0 && $missingCognitive > 0) {
            $add($warnings, 'COGNITIVE_LEVEL_MISSING', "{$missingCognitive} of {$n} questions have no cognitive (Bloom) level.", ['count' => $missingCognitive]);
        }
        $checks['questions'] = $n > 0 && !collect($errors)->contains(fn ($e) => str_starts_with($e['code'], 'QUESTION_') || in_array($e['code'], ['DUPLICATE_QUESTION_NUMBER', 'DIFFICULTY_INVALID', 'COGNITIVE_LEVEL_INVALID'], true));
        $checks['mappings'] = !collect($errors)->contains(fn ($e) => in_array($e['code'], ['LO_OUTSIDE_COURSE', 'PO_OUTSIDE_PROGRAM', 'LO_MAPPING_MISSING', 'PO_MAPPING_MISSING'], true));

        // Marks
        $sum = round((float) $questions->sum('marks'), 2);
        if ($n > 0 && abs($sum - (float) $version->total_marks) > (float) $cfg['marks_tolerance']) {
            $add($errors, 'TOTAL_MARKS_MISMATCH', "Question marks add up to {$sum}, but the version total is " . (float) $version->total_marks . '.', ['expected' => (float) $version->total_marks, 'actual' => $sum]);
        }
        if ((float) $version->total_marks > (float) $cfg['max_marks']) {
            $add($errors, 'TOTAL_MARKS_TOO_HIGH', "Total marks may not exceed {$cfg['max_marks']}.");
        }
        if ((int) $version->question_count !== $n) {
            $add($errors, 'QUESTION_COUNT_MISMATCH', "question_count ({$version->question_count}) does not match the snapshot ({$n}).");
        }
        $checks['marks'] = !collect($errors)->contains(fn ($e) => in_array($e['code'], ['TOTAL_MARKS_MISMATCH', 'TOTAL_MARKS_TOO_HIGH', 'QUESTION_COUNT_MISMATCH'], true));

        return $this->result($errors, $warnings, $checks, ['question_count' => $n, 'total_marks' => (float) $version->total_marks, 'marks_sum' => $sum]);
    }

    /** Finalization adds blueprint compliance (STEP 37 snapshot) on top of the base checks. */
    public function validateBeforeFinalization(AssessmentVersion $version): array
    {
        $base = $this->validateVersion($version);
        $errors = $base['errors'];
        $warnings = $base['warnings'];
        $checks = $base['checks'];
        $version->loadMissing(['questions', 'blueprint']);
        $bp = $version->blueprint;
        $tol = (float) config('assessment_versioning.blueprint_tolerance', 5);
        $profile = $this->profile($version);

        if (!$bp) {
            $warnings[] = ['code' => 'NO_BLUEPRINT_SNAPSHOT', 'message' => 'No blueprint snapshot is attached; blueprint compliance was not checked.'];
            $checks['blueprint'] = null;
        } else {
            $bpErrors = 0;
            if ((int) $bp->question_count !== (int) $version->question_count) {
                $errors[] = ['code' => 'BLUEPRINT_QUESTION_COUNT', 'message' => "Blueprint v{$bp->blueprint_version} plans {$bp->question_count} questions but the version has {$version->question_count}.", 'expected' => (int) $bp->question_count, 'actual' => (int) $version->question_count];
                $bpErrors++;
            }
            if (abs((float) $bp->total_marks - (float) $version->total_marks) > (float) config('assessment_versioning.marks_tolerance', 0.01)) {
                $errors[] = ['code' => 'BLUEPRINT_TOTAL_MARKS', 'message' => "Blueprint v{$bp->blueprint_version} plans " . (float) $bp->total_marks . ' marks but the version totals ' . (float) $version->total_marks . '.', 'expected' => (float) $bp->total_marks, 'actual' => (float) $version->total_marks];
                $bpErrors++;
            }
            foreach ([
                ['difficulty', $bp->difficulty_distribution ?? [], $profile['difficulty']], ['cognitive', $bp->cognitive_distribution ?? [], $profile['cognitive']],
                ['question_types', $bp->question_type_distribution ?? [], $profile['question_types']], ['learning_outcomes', $bp->learning_outcome_distribution ?? [], $profile['learning_outcomes']],
                ['program_outcomes', $bp->program_outcome_distribution ?? [], $profile['program_outcomes']],
            ] as [$dim, $planned, $actual]) {
                foreach ($planned as $key => $row) {
                    $a = (float) ($actual[$key]['percentage'] ?? 0);
                    $diff = round($a - (float) $row['percentage'], 1);
                    if (abs($diff) > $tol) {
                        $warnings[] = ['code' => 'BLUEPRINT_DEVIATION', 'message' => ucfirst(str_replace('_', ' ', $dim)) . " \"{$row['label']}\" is planned at {$row['percentage']}% but the version has {$a}% (" . ($diff > 0 ? '+' : '') . "{$diff} pp).", 'dimension' => $dim, 'key' => $key, 'planned' => (float) $row['percentage'], 'actual' => $a, 'difference' => $diff];
                    }
                }
            }
            $checks['blueprint'] = $bpErrors === 0;
        }

        return $this->result($errors, $warnings, $checks, $base['totals'] + ['profile' => $profile, 'blueprint_version' => $bp?->blueprint_version]);
    }

    /** Actual distribution of the snapshot (count-based for difficulty/Bloom/type, marks-based for CO/PO/topic), keyed like the blueprint snapshot. */
    public function profile(AssessmentVersion $version): array
    {
        $version->loadMissing(['questions.learningOutcome:id,code', 'questions.programOutcome:id,code']);
        $qs = $version->questions;
        $n = $qs->count();
        $marks = (float) $qs->sum('marks');
        $count = function (callable $key, callable $label) use ($qs, $n): array {
            $out = [];
            foreach ($qs as $q) {
                $k = $key($q);
                if ($k === null || $k === '') {
                    continue;
                }
                $out[$k] ??= ['label' => $label($q), 'count' => 0, 'percentage' => 0.0];
                $out[$k]['count']++;
            }
            foreach ($out as $k => $row) {
                $out[$k]['percentage'] = $n ? round($row['count'] / $n * 100, 1) : 0.0;
            }
            ksort($out);

            return $out;
        };
        $byMarks = function (callable $key, callable $label) use ($qs, $marks): array {
            $out = [];
            foreach ($qs as $q) {
                $k = $key($q);
                if ($k === null || $k === '') {
                    continue;
                }
                $out[$k] ??= ['label' => $label($q), 'marks' => 0.0, 'percentage' => 0.0];
                $out[$k]['marks'] = round($out[$k]['marks'] + (float) $q->marks, 2);
            }
            foreach ($out as $k => $row) {
                $out[$k]['percentage'] = $marks ? round($row['marks'] / $marks * 100, 1) : 0.0;
            }
            ksort($out);

            return $out;
        };

        return [
            'question_count' => $n, 'total_marks' => round($marks, 2),
            'difficulty' => $count(fn ($q) => $q->difficulty_level, fn ($q) => ucfirst((string) $q->difficulty_level)),
            'cognitive' => $count(fn ($q) => $q->cognitive_level, fn ($q) => (string) $q->cognitive_level),
            'question_types' => $count(fn ($q) => $q->question_type, fn ($q) => ucfirst(str_replace('_', ' ', (string) $q->question_type))),
            'learning_outcomes' => $byMarks(fn ($q) => $q->learning_outcome_id ? 'lo:' . $q->learning_outcome_id : null, fn ($q) => $q->learningOutcome?->code ?? ('LO ' . $q->learning_outcome_id)),
            'program_outcomes' => $byMarks(fn ($q) => $q->program_outcome_id ? 'po:' . $q->program_outcome_id : null, fn ($q) => $q->programOutcome?->code ?? ('PO ' . $q->program_outcome_id)),
            'topics' => $byMarks(fn ($q) => $q->topic ? strtolower(trim($q->topic)) : null, fn ($q) => trim((string) $q->topic)),
        ];
    }

    protected function result(array $errors, array $warnings, array $checks, array $totals): array
    {
        return [
            'status' => $errors ? self::INVALID : ($warnings ? self::VALID_WITH_WARNINGS : self::VALID),
            'errors' => array_values($errors), 'warnings' => array_values($warnings), 'checks' => $checks, 'totals' => $totals,
            'error_count' => count($errors), 'warning_count' => count($warnings), 'validated_at' => now()->toISOString(),
        ];
    }
}
