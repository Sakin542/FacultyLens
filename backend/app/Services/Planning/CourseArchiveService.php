<?php

namespace App\Services\Planning;

use App\Models\Assessment;
use App\Models\Course;
use App\Models\CourseMaterial;
use App\Models\ExamPaper;
use App\Models\Rubric;
use App\Models\StudentSubmission;
use App\Services\Exam\ExamPaperExporter;
use App\Services\Exam\ItemAnalysisService;
use App\Support\ZipBuilder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * End-of-semester course file: everything about one offering in a single ZIP — course materials, every
 * assessment's question paper (with answer key), rubrics, item analysis, final exam papers and sets, the OBE pack
 * and, with student data access, the results sheet.
 */
class CourseArchiveService
{
    public const MAX_MATERIAL_BYTES = 200 * 1024 * 1024;

    public function __construct(
        protected ObePackService $obe,
        protected ExamPaperExporter $papers,
        protected ItemAnalysisService $items,
    ) {}

    /** @return array{zip: string, filename: string, manifest: list<string>, skipped: list<string>} */
    public function build(Course $course, bool $includeStudentData): array
    {
        $zip = new ZipBuilder();
        $manifest = [];
        $skipped = [];
        $add = function (string $name, string $contents) use ($zip, &$manifest) {
            $zip->add($name, $contents);
            $manifest[] = $name;
        };

        // 1. Course materials (syllabus, slides, references) from private storage
        $budget = self::MAX_MATERIAL_BYTES;
        foreach (CourseMaterial::query()->where('course_id', $course->id)->orderBy('id')->get() as $m) {
            $disk = Storage::disk('local');
            if (!$m->file_path || !$disk->exists($m->file_path)) {
                $skipped[] = "materials/{$m->file_name} (file missing from storage)";
                continue;
            }
            $size = (int) $disk->size($m->file_path);
            if ($size > $budget) {
                $skipped[] = "materials/{$m->file_name} (archive size limit reached)";
                continue;
            }
            $budget -= $size;
            $add('01-course-materials/'.$m->file_name, (string) $disk->get($m->file_path));
        }

        // 2. Assessments
        foreach (Assessment::query()->where('course_id', $course->id)->orderBy('assessment_date')->orderBy('id')->get() as $i => $a) {
            $dir = sprintf('02-assessments/%02d-%s', $i + 1, Str::slug($a->title) ?: 'assessment');
            $questions = $a->questions()->with('learningOutcome:id,code')->orderBy('id')->get();
            $add("{$dir}/question-paper-with-answers.pdf", Pdf::loadView('obe.assessment-pdf', ['course' => $course, 'assessment' => $a, 'questions' => $questions])->setPaper('a4')->output());

            $rubrics = Rubric::query()->where('assessment_id', $a->id)->where('status', '!=', Rubric::STATUS_ARCHIVED)->with(['criteria', 'question:id,question_number'])->get();
            if ($rubrics->isNotEmpty()) {
                $add("{$dir}/rubrics.txt", $this->rubricsText($rubrics));
            }

            $analysis = $this->items->analyze($a);
            if ($analysis['sufficient']) {
                $add("{$dir}/item-analysis.csv", $this->itemCsv($analysis));
            }

            if ($includeStudentData) {
                $results = $this->resultsCsv($a);
                if ($results !== null) {
                    $add("{$dir}/results.csv", $results);
                }
            }

            foreach (ExamPaper::query()->where('assessment_id', $a->id)->where('status', 'final')->get() as $paper) {
                $add("{$dir}/exam-papers/set-{$paper->set_label}-".Str::slug($paper->title).'.pdf', $this->papers->pdf($paper, true));
            }
        }

        // 3. OBE evidence
        foreach ($this->obe->files($course, $includeStudentData) as $name => $contents) {
            $add('03-obe/'.$name, $contents);
        }

        $add('README.txt', $this->readme($course, $manifest, $skipped, $includeStudentData));

        $filename = Str::slug($course->course_code.' '.CourseMetricsService::termLabel($course).' course file').'.zip';

        return ['zip' => $zip->output(), 'filename' => $filename, 'manifest' => $manifest, 'skipped' => $skipped];
    }

    protected function rubricsText($rubrics): string
    {
        $out = [];
        foreach ($rubrics as $r) {
            $out[] = 'Q'.($r->question?->question_number ?? '?').' — '.$r->title.' ('.(float) $r->total_marks.' marks, '.strtolower((string) $r->status).')';
            foreach ($r->criteria as $c) {
                $out[] = '  - '.$c->criterion.' ['.(float) $c->max_marks.']: '.trim((string) $c->description);
            }
            $out[] = '';
        }

        return implode("\r\n", $out);
    }

    protected function itemCsv(array $analysis): string
    {
        $h = fopen('php://temp', 'r+');
        fputcsv($h, ['Question', 'Max marks', 'Mean marks', 'Difficulty p', 'Discrimination D', 'Item-rest r', 'Flags', 'Recommendation']);
        foreach ($analysis['items'] as $it) {
            fputcsv($h, [$it['question_number'], $it['max_marks'], $it['mean_marks'], $it['difficulty_index'], $it['discrimination_index'], $it['item_rest_correlation'], implode(' ', $it['flags']), $it['recommendation']]);
        }
        fputcsv($h, []);
        fputcsv($h, ["Cronbach's alpha", $analysis['summary']['cronbach_alpha'] ?? '']);
        rewind($h);

        return "\xEF\xBB\xBF".stream_get_contents($h);
    }

    protected function resultsCsv(Assessment $a): ?string
    {
        $subs = StudentSubmission::query()->where('assessment_id', $a->id)->with(['student:id,student_identifier,name', 'answers:id,student_submission_id,question_id,awarded_marks'])->get();
        if ($subs->isEmpty()) {
            return null;
        }
        $questions = $a->questions()->orderBy('id')->get(['id', 'question_number']);
        $h = fopen('php://temp', 'r+');
        fputcsv($h, array_merge(['Student ID', 'Name'], $questions->map(fn ($q) => 'Q'.$q->question_number)->all(), ['Total']));
        foreach ($subs as $s) {
            $marks = $questions->map(fn ($q) => $s->answers->firstWhere('question_id', $q->id)?->awarded_marks)->all();
            fputcsv($h, array_merge([$s->student?->student_identifier, $s->student?->name], $marks, [$s->awarded_marks]));
        }
        rewind($h);

        return "\xEF\xBB\xBF".stream_get_contents($h);
    }

    protected function readme(Course $course, array $manifest, array $skipped, bool $studentData): string
    {
        $lines = [
            "Course file — {$course->course_code} {$course->course_name} (".CourseMetricsService::termLabel($course).')',
            'Generated by FacultyLens on '.now()->toDayDateTimeString(),
            '',
            'Contents:',
            ...array_map(fn ($m) => '  '.$m, $manifest),
        ];
        if (!$studentData) {
            $lines[] = '';
            $lines[] = 'Student results and sample answers are not included (your role does not have student data access).';
        }
        if ($skipped) {
            $lines[] = '';
            $lines[] = 'Not included:';
            foreach ($skipped as $s) {
                $lines[] = '  '.$s;
            }
        }

        return implode("\r\n", $lines)."\r\n";
    }
}
