<?php

namespace App\Services\Reports;

use App\Models\Course;
use App\Models\LearningOutcome;
use App\Services\Analytics\OutcomeAnalyticsService;

/**
 * CO/LO Coverage Evidence and PO Coverage reports (STEP 11 / STEP 31). Wording is deliberately
 * "coverage evidence", never "accreditation compliance". PO data is only shown when configured.
 */
class OutcomeReportBuilder extends AbstractReportBuilder
{
    public function __construct(protected OutcomeAnalyticsService $outcomes) {}

    public function build(ReportContext $ctx): array
    {
        return $ctx->type === 'PO_COVERAGE' ? $this->po($ctx) : $this->co($ctx);
    }

    protected function co(ReportContext $ctx): array
    {
        $lo = $this->outcomes->learningOutcomes($ctx->courseIds, $ctx->assessmentIds);
        $rows = array_map(fn ($o) => ['course_code' => $o['course_code'], 'learning_outcome' => $o['code'], 'description' => mb_substr((string) $o['description'], 0, 140),
            'target_coverage' => $this->targetCoverage($o['course_id'], $ctx), 'actual_coverage' => $o['coverage_percentage'], 'questions' => $o['questions'],
            'strong' => $o['strong'], 'weak' => $o['weak'], 'not_aligned' => $o['not_aligned'], 'status' => $o['status']], $lo['outcomes']);

        $summary = [
            $this->kv('Courses in Scope', count($ctx->courseIds)),
            $this->kv('Assessments in Scope', count($ctx->assessmentIds)),
            $this->kv('Analyzed Assessments', $lo['analyzed_assessments']),
            $this->kv('Learning Outcomes', $lo['total_outcomes']),
            $this->kv('Outcomes with Strong Evidence', $lo['covered_outcomes']),
            $this->kv('Coverage', $this->na($lo['coverage_percentage'], '%', 'No outcomes')),
        ];
        $warnings = [];
        if ($lo['total_outcomes'] === 0) {
            $warnings[] = 'No learning outcomes are defined for the selected scope.';
        }
        if ($lo['analyzed_assessments'] === 0 && $lo['total_outcomes'] > 0) {
            $warnings[] = 'No completed AI alignment analysis exists; strong/weak counts are unavailable.';
        }

        return $this->document($ctx, $summary, [$this->section('note', 'Interpretation', [], 'Coverage percentage = questions with strong alignment ÷ aligned questions per outcome. This is CO/LO coverage evidence from assessment content, not an accreditation compliance claim.')], [
            $this->table('co_coverage', 'CO/LO Coverage Evidence', ['course_code' => 'Course', 'learning_outcome' => 'Learning Outcome', 'description' => 'Description', 'target_coverage' => 'Target Coverage %', 'actual_coverage' => 'Actual Coverage %',
                'questions' => 'Aligned Questions', 'strong' => 'Strong', 'weak' => 'Weak', 'not_aligned' => 'Not Aligned', 'status' => 'Status'], $rows),
        ], $warnings);
    }

    /** Even-share target per course (100 / number of outcomes) — an informational reference, not a policy target. */
    protected function targetCoverage(int $courseId, ReportContext $ctx): ?float
    {
        static $cache = [];
        if (! isset($cache[$courseId])) {
            $n = LearningOutcome::where('course_id', $courseId)->count();
            $cache[$courseId] = $n ? round(100 / $n, 1) : null;
        }

        return $cache[$courseId];
    }

    protected function po(ReportContext $ctx): array
    {
        $po = $this->outcomes->programOutcomes($ctx->courseIds);
        if (! ($po['configured'] ?? false)) {
            return $this->document($ctx, [$this->kv('Courses in Scope', count($ctx->courseIds)), $this->kv('PO Mapping', 'Not configured')], [],
                [$this->table('po_coverage', 'PO Coverage', ['message' => 'Status'], [['message' => 'PO Mapping is not configured for the selected course(s).']])],
                ['PO Mapping is not configured for the selected course(s). No PO values are produced.']);
        }
        $rows = array_map(fn ($p) => ['po' => $p['code'], 'title' => $p['title'], 'courses' => $p['courses'], 'mapped_cos' => $p['mapped_cos'], 'mapped_questions' => $p['mapped_questions'],
            'strong_mappings' => $p['strong_mappings'], 'weak_mappings' => $p['weak_mappings'], 'coverage' => $p['evidence_percent'], 'student_performance' => $ctx->studentDataAssessmentIds !== [] || $ctx->user->isAdmin() ? $p['student_performance_percent'] : null, 'alignment' => $p['status']], $po['program_outcomes']);
        $courses = array_map(fn ($c) => ['course_code' => $c['course_code'], 'analyzed' => $c['analyzed'] ? 'Yes' : 'No', 'stale' => $c['is_stale'] ? 'Yes' : 'No', 'analyzed_at' => $c['analyzed_at'], 'co_coverage' => $c['co_coverage_percent'], 'po_evidence' => $c['po_evidence_percent'], 'question_mapping' => $c['question_mapping_percent']], $po['courses']);
        $courseNames = Course::whereIn('id', $ctx->courseIds)->pluck('course_code')->implode(', ');

        $summary = [
            $this->kv('Courses in Scope', count($ctx->courseIds)),
            $this->kv('Courses with Program', count($po['courses'])),
            $this->kv('Courses Analyzed', $po['analyzed_courses']),
            $this->kv('Program Outcomes', count($rows)),
            $this->kv('Assessed POs', count(array_filter($rows, fn ($r) => $r['alignment'] === 'ASSESSED'))),
        ];
        $warnings = $po['message'] ? [$po['message']] : [];
        if (count($po['courses']) < count($ctx->courseIds)) {
            $warnings[] = (count($ctx->courseIds) - count($po['courses'])).' course(s) have no program configured and are excluded from PO evidence.';
        }

        return $this->document($ctx, $summary, [$this->section('note', 'Interpretation', [], 'PO evidence is derived from confirmed CO→PO mappings and assessed questions of the current CO/PO analysis run per course ('.$courseNames.'). It is evidence for review, not an accreditation compliance claim.')], [
            $this->table('po_coverage', 'PO Coverage', ['po' => 'PO', 'title' => 'Title', 'courses' => 'Courses', 'mapped_cos' => 'Mapped COs', 'mapped_questions' => 'Mapped Questions', 'strong_mappings' => 'Strong Mappings', 'weak_mappings' => 'Weak Mappings', 'coverage' => 'Evidence %', 'student_performance' => 'Student Performance %', 'alignment' => 'Alignment Status'], $rows),
            $this->table('courses', 'Per-Course CO/PO Analysis', ['course_code' => 'Course', 'analyzed' => 'Analyzed', 'stale' => 'Stale', 'analyzed_at' => 'Analyzed At', 'co_coverage' => 'CO Coverage %', 'po_evidence' => 'PO Evidence %', 'question_mapping' => 'Question Mapping %'], $courses),
        ], $warnings);
    }
}
