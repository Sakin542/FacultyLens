<?php

namespace App\Services\Admin;

use App\Models\DataQualityFinding;
use App\Models\DocumentProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only data-quality checks. The scanner only writes to data_quality_findings: it never edits courses,
 * assessments, documents or accounts. Findings are keyed by check + record, so a re-scan refreshes existing rows,
 * and findings whose condition no longer holds are marked resolved automatically (ignored findings stay ignored).
 */
class DataQualityScanner
{
    /** Per-check cap so one systemic problem cannot flood the table. */
    protected const LIMIT = 500;

    public const CHECKS = [
        'course_without_outcomes' => ['category' => 'metadata', 'severity' => 'medium', 'label' => 'Course has no learning outcomes'],
        'course_without_program' => ['category' => 'metadata', 'severity' => 'low', 'label' => 'Course is not linked to a program'],
        'duplicate_course' => ['category' => 'integrity', 'severity' => 'medium', 'label' => 'Duplicate course code in the same term'],
        'assessment_without_questions' => ['category' => 'integrity', 'severity' => 'medium', 'label' => 'Assessment has no questions'],
        'assessment_marks_mismatch' => ['category' => 'integrity', 'severity' => 'high', 'label' => 'Question marks do not add up to the assessment total'],
        'question_without_outcome' => ['category' => 'metadata', 'severity' => 'low', 'label' => 'Questions not mapped to a learning outcome'],
        'document_processing_failed' => ['category' => 'documents', 'severity' => 'medium', 'label' => 'Document text extraction failed'],
        'document_processing_stuck' => ['category' => 'documents', 'severity' => 'medium', 'label' => 'Document stuck in processing'],
        'document_index_failed' => ['category' => 'ai', 'severity' => 'medium', 'label' => 'Document embedding/indexing failed or stale'],
        'chunks_missing_embeddings' => ['category' => 'ai', 'severity' => 'low', 'label' => 'Indexed document has chunks without embeddings'],
        'analysis_failed' => ['category' => 'ai', 'severity' => 'low', 'label' => 'Latest AI analysis of the assessment failed'],
        'user_missing_department' => ['category' => 'accounts', 'severity' => 'low', 'label' => 'Account has no department'],
    ];

    /** @return array{checks: int, findings: int, new: int, auto_resolved: int, by_check: array<string, int>} */
    public function scan(): array
    {
        $seen = [];
        $new = 0;
        $byCheck = [];
        $now = now();

        foreach (array_keys(self::CHECKS) as $check) {
            $rows = $this->{'check'.str_replace('_', '', ucwords($check, '_'))}();
            $byCheck[$check] = count($rows);
            foreach ($rows as $row) {
                $fingerprint = $check.':'.$row['entity_type'].':'.($row['entity_id'] ?? '-');
                $seen[] = $fingerprint;
                $existing = DataQualityFinding::query()->where('fingerprint', $fingerprint)->first();
                $def = self::CHECKS[$check];
                if ($existing) {
                    $reopen = $existing->status === 'resolved';
                    $existing->forceFill([
                        'entity_label' => $row['label'],
                        'explanation' => $row['explanation'],
                        'remediation' => $row['remediation'],
                        'severity' => $row['severity'] ?? $def['severity'],
                        'last_detected_at' => $now,
                    ] + ($reopen ? ['status' => 'open', 'auto_resolved' => false, 'resolved_at' => null, 'resolved_by' => null, 'resolution_note' => null] : []))->save();
                    continue;
                }
                DataQualityFinding::query()->create([
                    'check' => $check,
                    'category' => $def['category'],
                    'severity' => $row['severity'] ?? $def['severity'],
                    'entity_type' => $row['entity_type'],
                    'entity_id' => $row['entity_id'],
                    'entity_label' => mb_substr((string) $row['label'], 0, 191),
                    'fingerprint' => $fingerprint,
                    'explanation' => mb_substr($row['explanation'], 0, 500),
                    'remediation' => mb_substr($row['remediation'], 0, 500),
                    'status' => 'open',
                    'first_detected_at' => $now,
                    'last_detected_at' => $now,
                ]);
                $new++;
            }
        }

        // Revalidation: a finding that was not detected again no longer holds
        $autoResolved = DataQualityFinding::query()
            ->whereIn('status', ['open', 'acknowledged'])
            ->whereNotIn('fingerprint', $seen ?: ['__none__'])
            ->update(['status' => 'resolved', 'auto_resolved' => true, 'resolved_at' => $now, 'resolved_by' => null, 'resolution_note' => 'No longer detected on re-scan.', 'updated_at' => $now]);

        return ['checks' => count(self::CHECKS), 'findings' => count($seen), 'new' => $new, 'auto_resolved' => $autoResolved, 'by_check' => $byCheck];
    }

    // ------------------------------------------------------------------------------------------------ checks

    protected function checkCourseWithoutOutcomes(): array
    {
        return DB::table('courses')
            ->leftJoin('learning_outcomes', 'learning_outcomes.course_id', '=', 'courses.id')
            ->where('courses.status', '!=', 'archived')
            ->groupBy('courses.id', 'courses.course_code', 'courses.course_name')
            ->havingRaw('COUNT(learning_outcomes.id) = 0')
            ->limit(self::LIMIT)
            ->get(['courses.id', 'courses.course_code', 'courses.course_name'])
            ->map(fn ($c) => $this->row('Course', $c->id, "{$c->course_code} — {$c->course_name}",
                'The course has no learning outcomes, so questions cannot be aligned and OBE reports will be empty.',
                'Ask the course owner to add learning outcomes (Course → Learning outcomes).'))
            ->all();
    }

    protected function checkCourseWithoutProgram(): array
    {
        if (!Schema::hasColumn('courses', 'program_id')) {
            return [];
        }

        return DB::table('courses')->whereNull('program_id')->where('status', '!=', 'archived')->limit(self::LIMIT)
            ->get(['id', 'course_code', 'course_name'])
            ->map(fn ($c) => $this->row('Course', $c->id, "{$c->course_code} — {$c->course_name}",
                'The course is not linked to a program, so CO→PO mapping and program reports exclude it.',
                'Link the course to its program from the course settings.'))
            ->all();
    }

    protected function checkDuplicateCourse(): array
    {
        $groups = DB::table('courses')
            ->select('course_code', 'semester', 'academic_year', 'user_id', DB::raw('COUNT(*) as n'), DB::raw('MIN(id) as first_id'))
            ->groupBy('course_code', 'semester', 'academic_year', 'user_id')
            ->havingRaw('COUNT(*) > 1')
            ->limit(self::LIMIT)->get();

        return $groups->map(fn ($g) => $this->row('Course', (int) $g->first_id, "{$g->course_code} ({$g->semester} {$g->academic_year})",
            "{$g->n} courses share this code, term and owner.",
            'Confirm with the owner whether one is a duplicate; archive the extra copy instead of deleting it.'))->all();
    }

    protected function checkAssessmentWithoutQuestions(): array
    {
        return DB::table('assessments')
            ->leftJoin('questions', 'questions.assessment_id', '=', 'assessments.id')
            ->groupBy('assessments.id', 'assessments.title')
            ->havingRaw('COUNT(questions.id) = 0')
            ->limit(self::LIMIT)
            ->get(['assessments.id', 'assessments.title'])
            ->map(fn ($a) => $this->row('Assessment', $a->id, (string) $a->title,
                'The assessment has no questions, so analysis, blueprints and grading cannot run.',
                'The owner should add questions or upload the question paper.'))
            ->all();
    }

    protected function checkAssessmentMarksMismatch(): array
    {
        return DB::table('assessments')
            ->join('questions', 'questions.assessment_id', '=', 'assessments.id')
            ->groupBy('assessments.id', 'assessments.title', 'assessments.total_marks')
            ->havingRaw('ABS(SUM(questions.marks) - assessments.total_marks) > 0.01')
            ->limit(self::LIMIT)
            ->get(['assessments.id', 'assessments.title', 'assessments.total_marks', DB::raw('SUM(questions.marks) as question_total')])
            ->map(fn ($a) => $this->row('Assessment', $a->id, (string) $a->title,
                sprintf('Questions add up to %s marks but the assessment total is %s.', $this->num($a->question_total), $this->num($a->total_marks)),
                'The owner should correct either the question marks or the assessment total. FacultyLens does not change marks automatically.'))
            ->all();
    }

    protected function checkQuestionWithoutOutcome(): array
    {
        return DB::table('questions')
            ->join('assessments', 'assessments.id', '=', 'questions.assessment_id')
            ->whereNull('questions.learning_outcome_id')
            ->groupBy('assessments.id', 'assessments.title')
            ->limit(self::LIMIT)
            ->get(['assessments.id', 'assessments.title', DB::raw('COUNT(questions.id) as n')])
            ->map(fn ($a) => $this->row('Assessment', $a->id, (string) $a->title,
                "{$a->n} question(s) are not mapped to a learning outcome.",
                'Map each question to a learning outcome so outcome attainment can be measured.'))
            ->all();
    }

    protected function checkDocumentProcessingFailed(): array
    {
        return DB::table('document_processings')->where('processing_status', 'failed')->limit(self::LIMIT)
            ->get(['id', 'original_file_name'])
            ->map(fn ($d) => $this->row('Document', $d->id, (string) $d->original_file_name,
                'Text extraction failed for this document, so it is excluded from AI analysis and document chat.',
                'Reprocess the document from its page, or ask the owner to upload a text-based PDF.'))
            ->all();
    }

    protected function checkDocumentProcessingStuck(): array
    {
        return DB::table('document_processings')->where('processing_status', 'processing')->where('updated_at', '<', now()->subHour())->limit(self::LIMIT)
            ->get(['id', 'original_file_name', 'updated_at'])
            ->map(fn ($d) => $this->row('Document', $d->id, (string) $d->original_file_name,
                'The document has been "processing" for over an hour; its job probably failed or was lost.',
                'Check Failed jobs in Queues, then reprocess the document.'))
            ->all();
    }

    protected function checkDocumentIndexFailed(): array
    {
        if (!Schema::hasColumn('document_processings', 'indexing_status')) {
            return [];
        }

        return DB::table('document_processings')->whereIn('indexing_status', [DocumentProcessing::INDEX_FAILED, DocumentProcessing::INDEX_STALE])->limit(self::LIMIT)
            ->get(['id', 'original_file_name', 'indexing_status'])
            ->map(fn ($d) => $this->row('Document', $d->id, (string) $d->original_file_name,
                $d->indexing_status === DocumentProcessing::INDEX_STALE
                    ? 'The document changed after it was indexed; document chat may cite outdated text.'
                    : 'Embedding generation failed, so document chat cannot retrieve this document.',
                'Reprocess the document to rebuild its index.'))
            ->all();
    }

    protected function checkChunksMissingEmbeddings(): array
    {
        if (!Schema::hasTable('document_chunks')) {
            return [];
        }

        return DB::table('document_chunks')
            ->join('document_processings', 'document_processings.id', '=', 'document_chunks.document_processing_id')
            ->where('document_processings.indexing_status', DocumentProcessing::INDEX_INDEXED)
            ->whereNull('document_chunks.embedding')
            ->groupBy('document_processings.id', 'document_processings.original_file_name')
            ->limit(self::LIMIT)
            ->get(['document_processings.id', 'document_processings.original_file_name', DB::raw('COUNT(document_chunks.id) as n')])
            ->map(fn ($d) => $this->row('Document', $d->id, (string) $d->original_file_name,
                "The document is marked indexed but {$d->n} chunk(s) have no embedding.",
                'Reprocess the document to regenerate embeddings.'))
            ->all();
    }

    protected function checkAnalysisFailed(): array
    {
        $latest = DB::table('analysis_reports')->select('assessment_id', DB::raw('MAX(id) as id'))->groupBy('assessment_id');

        return DB::table('analysis_reports')
            ->joinSub($latest, 'latest', 'latest.id', '=', 'analysis_reports.id')
            ->join('assessments', 'assessments.id', '=', 'analysis_reports.assessment_id')
            ->where('analysis_reports.analysis_status', 'failed')
            ->limit(self::LIMIT)
            ->get(['assessments.id', 'assessments.title'])
            ->map(fn ($a) => $this->row('Assessment', $a->id, (string) $a->title,
                'The most recent AI analysis of this assessment failed; faculty see no current analysis.',
                'Check AI service health, then the owner can re-run the analysis.'))
            ->all();
    }

    protected function checkUserMissingDepartment(): array
    {
        return DB::table('users')->where(fn ($q) => $q->whereNull('department')->orWhere('department', ''))
            ->where(fn ($q) => $q->where('is_active', true)->orWhereNull('is_active'))
            ->whereRaw('UPPER(COALESCE(role, ?)) <> ?', ['FACULTY', 'ADMIN'])
            ->limit(self::LIMIT)
            ->get(['id', 'name'])
            ->map(fn ($u) => $this->row('User', $u->id, (string) $u->name,
                'The account has no department, so department heads and department reports do not include it.',
                'Set the department from Users → account details.'))
            ->all();
    }

    protected function row(string $type, ?int $id, string $label, string $explanation, string $remediation, ?string $severity = null): array
    {
        return ['entity_type' => $type, 'entity_id' => $id, 'label' => $label, 'explanation' => $explanation, 'remediation' => $remediation, 'severity' => $severity];
    }

    protected function num(mixed $v): string
    {
        return rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
    }
}
