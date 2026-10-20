<?php

namespace App\Services\Planning;

use App\Models\Assessment;
use App\Models\CoPoMapping;
use App\Models\Course;
use App\Models\LearningOutcome;
use App\Models\ProgramOutcome;
use App\Models\Question;
use App\Models\StudentAnswer;
use App\Support\ZipBuilder;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Outcome-based education evidence for one course offering, in the shape accreditation reviewers ask for:
 * CO attainment, the CO→PO mapping matrix, PO attainment, the assessment-to-CO evidence trail, and (with student
 * data access) anonymised high/median/low sample answers per question.
 */
class ObePackService
{
    public function __construct(protected CourseMetricsService $metrics) {}

    public function data(Course $course, bool $includeSamples): array
    {
        $course->loadMissing('user:id,name,department');
        $co = $this->metrics->coAttainment($course);
        $po = $this->metrics->poAttainment($course, $co);
        $outcomes = LearningOutcome::query()->where('course_id', $course->id)->orderBy('sort_order')->get(['id', 'code', 'description', 'cognitive_level']);
        $mappings = CoPoMapping::query()->where('course_id', $course->id)->get(['learning_outcome_id', 'program_outcome_id', 'mapping_level']);
        $pos = ProgramOutcome::query()->whereIn('id', $mappings->pluck('program_outcome_id')->unique())->orderBy('sort_order')->orderBy('code')->get(['id', 'code', 'title']);

        $matrix = $outcomes->map(fn ($lo) => [
            'code' => $lo->code,
            'levels' => $pos->mapWithKeys(fn ($p) => [$p->code => (int) ($mappings->first(fn ($m) => $m->learning_outcome_id === $lo->id && $m->program_outcome_id === $p->id)?->mapping_level ?? 0)])->all(),
        ])->values()->all();

        $assessments = Assessment::query()->where('course_id', $course->id)->orderBy('assessment_date')->orderBy('id')->get();
        $evidence = $assessments->map(function (Assessment $a) use ($includeSamples) {
            $questions = Question::query()->where('assessment_id', $a->id)->with('learningOutcome:id,code')->orderBy('id')->get();

            return [
                'assessment' => $a,
                'questions' => $questions->map(fn (Question $q) => [
                    'number' => $q->question_number,
                    'text' => $q->question_text,
                    'marks' => (float) $q->marks,
                    'co' => $q->learningOutcome?->code,
                    'bloom' => $q->cognitive_level ?: $q->ai_cognitive_level,
                    'samples' => $includeSamples ? $this->samples($q) : [],
                ])->all(),
            ];
        })->all();

        return [
            'course' => $course,
            'generated_at' => now(),
            'co_target' => (float) config('obe.co_target_percent', 60),
            'po_target' => (float) config('obe.po_target_level', 2.0),
            'levels' => (array) config('obe.levels'),
            'outcomes' => $outcomes,
            'co_attainment' => $co,
            'po_attainment' => $po,
            'program_outcomes' => $pos,
            'matrix' => $matrix,
            'evidence' => $evidence,
            'include_samples' => $includeSamples,
        ];
    }

    /** Anonymised highest / median / lowest graded answers for a question. */
    protected function samples(Question $q): array
    {
        $graded = StudentAnswer::query()->where('question_id', $q->id)->whereNotNull('awarded_marks')->whereNotNull('answer_text')
            ->orderByDesc('awarded_marks')->orderBy('id')->get(['answer_text', 'awarded_marks'])->values();
        if ($graded->isEmpty()) {
            return [];
        }
        $picks = ['High' => $graded->first(), 'Median' => $graded[intdiv($graded->count(), 2)], 'Low' => $graded->last()];
        $out = [];
        $seen = [];
        foreach ($picks as $label => $answer) {
            $key = spl_object_id($answer);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = ['label' => $label, 'marks' => (float) $answer->awarded_marks, 'text' => mb_strimwidth((string) $answer->answer_text, 0, 1200, '…')];
        }

        return $out;
    }

    public function pdf(Course $course, bool $includeSamples): string
    {
        return Pdf::loadView('obe.report-pdf', $this->data($course, $includeSamples))->setPaper('a4', 'portrait')->output();
    }

    /** @return array<string, string> file name => contents */
    public function files(Course $course, bool $includeSamples): array
    {
        $data = $this->data($course, $includeSamples);
        $csv = function (array $rows): string {
            $h = fopen('php://temp', 'r+');
            foreach ($rows as $row) {
                fputcsv($h, $row);
            }
            rewind($h);

            return "\xEF\xBB\xBF".stream_get_contents($h); // BOM so Excel opens UTF-8 correctly
        };

        $coRows = [['CO', 'Description', 'Class average %', 'Attainment level (0-3)', 'Target %', 'Attained']];
        foreach ($data['co_attainment'] as $c) {
            $coRows[] = [$c['code'], $c['description'], $c['average_percent'] ?? 'not assessed', $c['level'] ?? '', $data['co_target'], $c['assessed'] ? ($c['attained'] ? 'Yes' : 'No') : 'Not assessed'];
        }
        $poRows = [['PO', 'Title', 'Attainment (0-3)', 'Target', 'Attained', 'Contributing COs']];
        foreach ($data['po_attainment'] as $p) {
            $poRows[] = [$p['code'], $p['title'], $p['attainment'] ?? 'no data', $data['po_target'], $p['attainment'] === null ? 'No data' : ($p['attained'] ? 'Yes' : 'No'), implode(' ', $p['contributing_cos'])];
        }
        $matrixRows = [array_merge(['CO'], $data['program_outcomes']->pluck('code')->all())];
        foreach ($data['matrix'] as $row) {
            $matrixRows[] = array_merge([$row['code']], array_values($row['levels']));
        }

        return [
            'OBE-report.pdf' => Pdf::loadView('obe.report-pdf', $data)->setPaper('a4', 'portrait')->output(),
            'co-attainment.csv' => $csv($coRows),
            'po-attainment.csv' => $csv($poRows),
            'co-po-matrix.csv' => $csv($matrixRows),
        ];
    }

    public function zip(Course $course, bool $includeSamples): string
    {
        $zip = new ZipBuilder();
        foreach ($this->files($course, $includeSamples) as $name => $contents) {
            $zip->add($name, $contents);
        }
        $zip->add('README.txt', $this->readme($course, $includeSamples));

        return $zip->output();
    }

    public function readme(Course $course, bool $includeSamples): string
    {
        $levels = collect((array) config('obe.levels'))->map(fn ($l) => "level {$l[1]} at {$l[0]}% or more")->implode(', ');

        return implode("\r\n", [
            "OBE evidence pack — {$course->course_code} {$course->course_name} (".CourseMetricsService::termLabel($course).')',
            'Generated by FacultyLens on '.now()->toDayDateTimeString(),
            '',
            'OBE-report.pdf     CO attainment, CO-PO matrix, PO attainment and the assessment evidence trail',
            'co-attainment.csv  class average and attainment level per course outcome',
            'po-attainment.csv  weighted program outcome attainment',
            'co-po-matrix.csv   mapping strength (0 none, 1 low, 2 medium, 3 high)',
            '',
            "Method: CO attainment is the marks-weighted class average on the questions mapped to each CO ({$levels}).",
            'PO attainment = sum(CO level x mapping strength) / sum(mapping strength) over the mapped COs.',
            $includeSamples ? 'Sample answers are anonymised (High / Median / Low).' : 'Sample answers are omitted (requires student data access).',
        ])."\r\n";
    }
}
