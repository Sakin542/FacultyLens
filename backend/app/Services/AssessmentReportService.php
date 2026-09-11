<?php

namespace App\Services;

use App\Models\AnalysisReport;
use App\Models\Assessment;
use App\Models\AssessmentReport;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Exception;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AssessmentReportService
{
    /**
     * Rating scale as defined in STEP 13 & STEP 17:
     * 90-100: EXCELLENT
     * 80-89:  GOOD
     * 70-79:  FAIR
     * 60-69:  NEEDS_REVIEW
     * < 60:   REQUIRES_ATTENTION
     */
    public function getRatingLabel(float $score): string
    {
        if ($score >= 90) {
            return 'EXCELLENT';
        }
        if ($score >= 80) {
            return 'GOOD';
        }
        if ($score >= 70) {
            return 'FAIR';
        }
        if ($score >= 60) {
            return 'NEEDS_REVIEW';
        }
        return 'REQUIRES_ATTENTION';
    }

    /**
     * Build comprehensive, authoritative report data for an assessment.
     * Throws an exception if analysis is not completed.
     */
    public function buildReportData(Assessment $assessment): array
    {
        $assessment->loadMissing([
            'course.user',
            'course.learningOutcomes',
            'questions.learningOutcome',
            'latestAnalysisReport.recommendations',
            'latestAnalysisReport.similarityMatches.previousQuestion',
            'latestAnalysisReport.learningOutcomeAlignments.learningOutcome',
            'latestReport',
        ]);

        $report = $assessment->latestAnalysisReport;

        if (!$report) {
            throw new Exception('No analysis report found for this assessment. Run AI analysis first.');
        }

        if ($report->analysis_status !== 'completed') {
            throw new Exception("Assessment analysis is not completed (current status: {$report->analysis_status}).");
        }

        // Parse JSON findings payload
        $rawFindingsPayload = $report->findings;
        if (is_string($rawFindingsPayload)) {
            $rawFindingsPayload = json_decode($rawFindingsPayload, true) ?? [];
        }
        if (!is_array($rawFindingsPayload)) {
            $rawFindingsPayload = [];
        }

        $qualityAnalysis = $rawFindingsPayload['quality_analysis'] ?? [];
        $alignmentAnalysis = $rawFindingsPayload['alignment_analysis'] ?? [];
        $similarityAnalysis = $rawFindingsPayload['similarity_analysis'] ?? [];
        $summary = $rawFindingsPayload['summary'] ?? [];

        // 1. Assessment & Course Information
        $course = $assessment->course;
        $faculty = $course->user;
        $totalQuestions = $assessment->questions->count();
        $totalMarks = (float) $assessment->total_marks;

        $assessmentInfo = [
            'id' => $assessment->id,
            'title' => $assessment->title,
            'type' => $assessment->type,
            'total_marks' => $totalMarks,
            'total_questions' => $totalQuestions,
            'duration_minutes' => $assessment->duration_minutes,
            'assessment_date' => $assessment->assessment_date?->format('Y-m-d'),
            'course_id' => $course->id,
            'course_code' => $course->course_code,
            'course_name' => $course->course_name,
            'department' => $faculty?->department ?? 'Academic Department',
            'faculty_name' => $faculty?->name ?? 'Faculty Member',
            'faculty_email' => $faculty?->email ?? '',
            'analyzed_at' => $report->analyzed_at?->format('Y-m-d H:i:s') ?? $report->created_at->format('Y-m-d H:i:s'),
            'generated_at' => now()->format('Y-m-d H:i:s'),
        ];

        // 2. Overall Quality Score & 6 Dimensions
        $overallScore = $report->overall_score !== null ? (float) $report->overall_score : 0.0;
        $overallRating = $this->getRatingLabel($overallScore);

        $dimensions = [
            'topic_coverage' => [
                'name' => 'Topic Coverage',
                'score' => (float) ($report->topic_coverage_score ?? $qualityAnalysis['topic_coverage_score'] ?? 0.0),
                'rating' => $this->getRatingLabel((float) ($report->topic_coverage_score ?? $qualityAnalysis['topic_coverage_score'] ?? 0.0)),
                'weight' => '25%',
                'description' => 'Evaluates how comprehensively assessment questions span the syllabus topics.',
            ],
            'learning_outcome_alignment' => [
                'name' => 'Learning Outcome Alignment',
                'score' => (float) ($report->learning_outcome_alignment_score ?? $qualityAnalysis['learning_outcome_alignment_score'] ?? 0.0),
                'rating' => $this->getRatingLabel((float) ($report->learning_outcome_alignment_score ?? $qualityAnalysis['learning_outcome_alignment_score'] ?? 0.0)),
                'weight' => '25%',
                'description' => 'Measures semantic coherence and alignment between questions and target learning outcomes.',
            ],
            'difficulty_balance' => [
                'name' => 'Difficulty Balance',
                'score' => (float) ($report->difficulty_balance_score ?? $qualityAnalysis['difficulty_balance_score'] ?? 0.0),
                'rating' => $this->getRatingLabel((float) ($report->difficulty_balance_score ?? $qualityAnalysis['difficulty_balance_score'] ?? 0.0)),
                'weight' => '15%',
                'description' => 'Measures balance between foundational (easy), intermediate (medium), and advanced (hard) marks.',
            ],
            'cognitive_diversity' => [
                'name' => 'Cognitive Diversity',
                'score' => (float) ($report->cognitive_level_balance_score ?? $qualityAnalysis['cognitive_level_balance_score'] ?? 0.0),
                'rating' => $this->getRatingLabel((float) ($report->cognitive_level_balance_score ?? $qualityAnalysis['cognitive_level_balance_score'] ?? 0.0)),
                'weight' => '15%',
                'description' => 'Evaluates coverage across Bloom’s Taxonomy levels (Remember, Understand, Apply, Analyze, Evaluate, Create).',
            ],
            'question_diversity' => [
                'name' => 'Question Diversity',
                'score' => (float) ($qualityAnalysis['question_diversity_score'] ?? 100.0 - (float) ($report->similarity_score ?? 0.0)),
                'rating' => $this->getRatingLabel((float) ($qualityAnalysis['question_diversity_score'] ?? 100.0 - (float) ($report->similarity_score ?? 0.0))),
                'weight' => '10%',
                'description' => 'Evaluates freshness and originality of items against historical question papers.',
            ],
            'marks_distribution' => [
                'name' => 'Marks Distribution',
                'score' => (float) ($qualityAnalysis['marks_distribution_score'] ?? 85.0),
                'rating' => $this->getRatingLabel((float) ($qualityAnalysis['marks_distribution_score'] ?? 85.0)),
                'weight' => '10%',
                'description' => 'Assesses proportionality and fairness of marks allocation across assessment sections.',
            ],
        ];

        // 3. Topic Coverage Details
        $topicDetails = $qualityAnalysis['topic_coverage_details'] ?? [];
        $topics = [];
        if (!empty($topicDetails['topics']) && is_array($topicDetails['topics'])) {
            foreach ($topicDetails['topics'] as $t) {
                $topics[] = [
                    'topic' => $t['topic'] ?? 'Unknown Topic',
                    'status' => strtoupper($t['status'] ?? 'COVERED'),
                    'question_count' => (int) ($t['question_count'] ?? 0),
                    'marks' => (float) ($t['marks'] ?? 0.0),
                ];
            }
        }
        $topicCoverageData = [
            'score' => $dimensions['topic_coverage']['score'],
            'rating' => $dimensions['topic_coverage']['rating'],
            'total_topics' => (int) ($topicDetails['total_topics'] ?? count($topics)),
            'covered_topics' => (int) ($topicDetails['covered_topics'] ?? count(array_filter($topics, fn($t) => $t['status'] === 'COVERED'))),
            'low_coverage_topics' => (int) ($topicDetails['low_coverage_topics'] ?? count(array_filter($topics, fn($t) => $t['status'] === 'LOW_COVERAGE'))),
            'not_covered_topics' => (int) ($topicDetails['not_covered_topics'] ?? count(array_filter($topics, fn($t) => $t['status'] === 'NOT_COVERED'))),
            'topics' => $topics,
        ];

        // 4. Learning Outcome Alignment Details
        $loAlignments = $report->learningOutcomeAlignments;
        $courseLos = $course->learningOutcomes;

        $loSummaries = [];
        foreach ($courseLos as $lo) {
            $matchingAlignments = $loAlignments->where('learning_outcome_id', $lo->id);
            $count = $matchingAlignments->count();
            $avgScore = $count > 0 ? (float) $matchingAlignments->avg('similarity_score') : 0.0;
            $status = $avgScore >= 0.70 ? 'STRONG' : ($avgScore >= 0.40 ? 'WEAK' : 'NOT_ALIGNED');

            $loSummaries[] = [
                'id' => $lo->id,
                'code' => $lo->code,
                'description' => $lo->description,
                'question_count' => $count,
                'average_score' => round($avgScore, 2),
                'alignment_status' => $status,
            ];
        }

        $questionLoMappings = [];
        foreach ($loAlignments as $loa) {
            $q = $assessment->questions->firstWhere('id', $loa->question_id);
            $questionLoMappings[] = [
                'question_number' => $q?->question_number ?? 0,
                'question_text' => $q?->question_text ?? 'Question',
                'lo_code' => $loa->learningOutcome?->code ?? 'N/A',
                'lo_description' => $loa->learningOutcome?->description ?? '',
                'similarity_score' => (float) $loa->similarity_score,
                'alignment' => strtoupper($loa->alignment ?? 'MODERATE'),
                'reasoning' => $loa->reasoning ?? '',
            ];
        }

        // Sort question mappings by question number
        usort($questionLoMappings, fn($a, $b) => $a['question_number'] <=> $b['question_number']);

        $loAlignmentData = [
            'score' => $dimensions['learning_outcome_alignment']['score'],
            'rating' => $dimensions['learning_outcome_alignment']['rating'],
            'outcomes' => $loSummaries,
            'question_mappings' => $questionLoMappings,
        ];

        // 5. Difficulty Distribution
        $diffCounts = ['easy' => 0, 'medium' => 0, 'hard' => 0];
        $diffMarks = ['easy' => 0.0, 'medium' => 0.0, 'hard' => 0.0];

        foreach ($assessment->questions as $q) {
            $diff = strtolower($q->ai_difficulty_level ?? $q->difficulty_level ?? $q->difficulty ?? 'medium');
            if (!isset($diffCounts[$diff])) {
                $diff = 'medium';
            }
            $diffCounts[$diff]++;
            $diffMarks[$diff] += (float) ($q->marks ?? 0.0);
        }

        $calcTotalMarks = array_sum($diffMarks) > 0 ? array_sum($diffMarks) : ($totalMarks > 0 ? $totalMarks : 1.0);

        $difficultyData = [
            'score' => $dimensions['difficulty_balance']['score'],
            'rating' => $dimensions['difficulty_balance']['rating'],
            'levels' => [
                'easy' => [
                    'label' => 'Easy / Foundational',
                    'question_count' => $diffCounts['easy'],
                    'marks' => $diffMarks['easy'],
                    'actual_percentage' => round(($diffMarks['easy'] / $calcTotalMarks) * 100, 1),
                    'target_percentage' => 30.0,
                ],
                'medium' => [
                    'label' => 'Medium / Intermediate',
                    'question_count' => $diffCounts['medium'],
                    'marks' => $diffMarks['medium'],
                    'actual_percentage' => round(($diffMarks['medium'] / $calcTotalMarks) * 100, 1),
                    'target_percentage' => 50.0,
                ],
                'hard' => [
                    'label' => 'Hard / Advanced',
                    'question_count' => $diffCounts['hard'],
                    'marks' => $diffMarks['hard'],
                    'actual_percentage' => round(($diffMarks['hard'] / $calcTotalMarks) * 100, 1),
                    'target_percentage' => 20.0,
                ],
            ],
        ];

        // 6. Cognitive Level Distribution (Bloom's Taxonomy)
        $bloomLevels = [
            'remember' => ['label' => 'Remember', 'count' => 0, 'marks' => 0.0],
            'understand' => ['label' => 'Understand', 'count' => 0, 'marks' => 0.0],
            'apply' => ['label' => 'Apply', 'count' => 0, 'marks' => 0.0],
            'analyze' => ['label' => 'Analyze', 'count' => 0, 'marks' => 0.0],
            'evaluate' => ['label' => 'Evaluate', 'count' => 0, 'marks' => 0.0],
            'create' => ['label' => 'Create', 'count' => 0, 'marks' => 0.0],
        ];

        foreach ($assessment->questions as $q) {
            $bloom = strtolower($q->ai_cognitive_level ?? $q->cognitive_level ?? $q->bloom_level ?? 'understand');
            if (!isset($bloomLevels[$bloom])) {
                $bloom = 'understand';
            }
            $bloomLevels[$bloom]['count']++;
            $bloomLevels[$bloom]['marks'] += (float) ($q->marks ?? 0.0);
        }

        foreach ($bloomLevels as $key => &$bData) {
            $bData['percentage'] = round(($bData['marks'] / $calcTotalMarks) * 100, 1);
        }
        unset($bData);

        $cognitiveData = [
            'score' => $dimensions['cognitive_diversity']['score'],
            'rating' => $dimensions['cognitive_diversity']['rating'],
            'levels' => $bloomLevels,
        ];

        // 7. Similar Questions / Duplicate Analysis
        $similarityMatches = $report->similarityMatches;
        $potentialDuplicates = [];
        $highlySimilar = [];
        $somewhatSimilar = [];

        foreach ($similarityMatches as $sm) {
            $currentQ = $assessment->questions->firstWhere('id', $sm->current_question_id);
            $item = [
                'current_question_number' => $currentQ?->question_number ?? 0,
                'current_question_text' => $currentQ?->question_text ?? 'Current Question',
                'previous_question_text' => $sm->previousQuestion?->question_text ?? 'Historical Question',
                'previous_assessment_title' => $sm->previousQuestion?->source_assessment ?? 'Previous Exam',
                'previous_year' => $sm->previousQuestion?->source_year ?? 'N/A',
                'similarity_score' => (float) $sm->similarity_score,
                'similarity_status' => $sm->similarity_status,
                'reasoning' => $sm->reasoning ?? '',
            ];

            $score = (float) $sm->similarity_score;
            if ($score >= 0.85 || strtolower($sm->similarity_status) === 'duplicate') {
                $potentialDuplicates[] = $item;
            } elseif ($score >= 0.70 || strtolower($sm->similarity_status) === 'high') {
                $highlySimilar[] = $item;
            } else {
                $somewhatSimilar[] = $item;
            }
        }

        $similarityData = [
            'score' => (float) ($report->similarity_score ?? 0.0),
            'rating' => $dimensions['question_diversity']['rating'],
            'total_matches' => $similarityMatches->count(),
            'potential_duplicates_count' => count($potentialDuplicates),
            'highly_similar_count' => count($highlySimilar),
            'somewhat_similar_count' => count($somewhatSimilar),
            'potential_duplicates' => $potentialDuplicates,
            'highly_similar' => $highlySimilar,
            'somewhat_similar' => $somewhatSimilar,
        ];

        // 8. AI Findings
        $findings = [];
        if (!empty($qualityAnalysis['findings']) && is_array($qualityAnalysis['findings'])) {
            foreach ($qualityAnalysis['findings'] as $f) {
                $findings[] = [
                    'category' => 'Quality & Balance',
                    'severity' => $f['severity'] ?? 'info',
                    'problem' => is_array($f) ? ($f['problem'] ?? $f['message'] ?? 'Assessment observation') : (string) $f,
                    'evidence' => is_array($f) ? ($f['evidence'] ?? null) : null,
                    'explanation' => is_array($f) ? ($f['explanation'] ?? null) : null,
                ];
            }
        }
        if (!empty($alignmentAnalysis['findings']) && is_array($alignmentAnalysis['findings'])) {
            foreach ($alignmentAnalysis['findings'] as $f) {
                $findings[] = [
                    'category' => 'Learning Outcome Alignment',
                    'severity' => $f['severity'] ?? 'warning',
                    'problem' => is_array($f) ? ($f['problem'] ?? $f['message'] ?? 'Alignment observation') : (string) $f,
                    'evidence' => is_array($f) ? ($f['evidence'] ?? null) : null,
                    'explanation' => is_array($f) ? ($f['explanation'] ?? null) : null,
                ];
            }
        }
        if (!empty($similarityAnalysis['findings']) && is_array($similarityAnalysis['findings'])) {
            foreach ($similarityAnalysis['findings'] as $f) {
                $findings[] = [
                    'category' => 'Question Similarity & Freshness',
                    'severity' => $f['severity'] ?? 'warning',
                    'problem' => is_array($f) ? ($f['problem'] ?? $f['message'] ?? 'Similarity observation') : (string) $f,
                    'evidence' => is_array($f) ? ($f['evidence'] ?? null) : null,
                    'explanation' => is_array($f) ? ($f['explanation'] ?? null) : null,
                ];
            }
        }

        // 9. Recommendations & Faculty Decisions
        $recommendations = $report->recommendations->map(function ($r) {
            return [
                'id' => $r->id,
                'category' => $r->category,
                'priority' => strtolower($r->priority),
                'status' => strtolower($r->status),
                'problem' => $r->problem,
                'recommendation' => $r->recommendation,
                'evidence' => $r->evidence,
                'explanation' => $r->explanation,
                'action_taken' => $r->status !== 'pending' ? ucfirst($r->status) : 'Pending Review',
            ];
        })->toArray();

        $recommendationSummary = [
            'total' => count($recommendations),
            'high' => count(array_filter($recommendations, fn($r) => $r['priority'] === 'high')),
            'medium' => count(array_filter($recommendations, fn($r) => $r['priority'] === 'medium')),
            'low' => count(array_filter($recommendations, fn($r) => $r['priority'] === 'low')),
            'accepted' => count(array_filter($recommendations, fn($r) => $r['status'] === 'accepted')),
            'dismissed' => count(array_filter($recommendations, fn($r) => $r['status'] === 'dismissed')),
            'reviewed' => count(array_filter($recommendations, fn($r) => $r['status'] === 'reviewed')),
            'pending' => count(array_filter($recommendations, fn($r) => $r['status'] === 'pending')),
        ];

        // 10. Existing PDF Report Status (if any)
        $latestReportModel = $assessment->latestReport;

        return [
            'assessment' => $assessmentInfo,
            'overall_quality' => [
                'score' => $overallScore,
                'rating' => $overallRating,
                'dimensions' => $dimensions,
            ],
            'topic_coverage' => $topicCoverageData,
            'learning_outcome_alignment' => $loAlignmentData,
            'difficulty_distribution' => $difficultyData,
            'cognitive_distribution' => $cognitiveData,
            'similar_questions' => $similarityData,
            'findings' => $findings,
            'recommendations' => $recommendations,
            'recommendation_summary' => $recommendationSummary,
            'student_performance' => $this->buildStudentPerformanceSection($assessment),
            'co_po_mapping' => $this->buildCoPoSection($assessment),
            'assessment_blueprint' => app(AssessmentBlueprintService::class)->reportSection($assessment),
            'generated_report' => $latestReportModel ? [
                'id' => $latestReportModel->id,
                'uuid' => $latestReportModel->report_uuid,
                'file_name' => $latestReportModel->file_name,
                'file_size' => $latestReportModel->file_size,
                'generation_status' => $latestReportModel->generation_status,
                'is_shareable' => $latestReportModel->isShareActive(),
                'share_token' => $latestReportModel->isShareActive() ? $latestReportModel->share_token : null,
                'generated_at' => $latestReportModel->generated_at?->format('Y-m-d H:i:s'),
            ] : null,
            'disclaimer' => 'This academic assessment report is generated as a decision-support document to assist university faculty in continuous curriculum quality enhancement. It provides analytical insights and constructive recommendations based on syllabus and learning outcome alignment, but does not replace expert academic judgment, university accreditation policies, or faculty discretion.',
            'engine_metadata' => [
                'system' => 'FacultyLens Academic Decision Support System',
                'version' => '1.0.0',
                'analysis_report_id' => $report->id,
            ],
        ];
    }

    /**
     * STEP 30: Student performance summary for the report, only when a completed analysis with
     * finalized grades exists. Aggregate statistics only — no student identity.
     */
    protected function buildStudentPerformanceSection(Assessment $assessment): ?array
    {
        $run = \App\Models\PerformanceAnalysisRun::where('assessment_id', $assessment->id)->where('is_current', true)->first();
        if (!$run || !$run->isCompleted() || (int) $run->finalized_answer_count === 0) {
            return null;
        }

        $service = app(\App\Services\StudentPerformanceService::class);
        $data = $service->present($run, true);
        $pct = fn ($v) => $v === null ? 'N/A' : number_format((float) $v, 1) . '%';
        $label = fn ($s) => ucwords(strtolower(str_replace('_', ' ', (string) $s)));

        return [
            'analyzed_at' => $run->analyzed_at?->format('Y-m-d H:i'),
            'is_stale' => $data['is_stale'],
            'students' => $data['student_count'],
            'finalized_answers' => $data['finalized_answer_count'],
            'overall' => $pct($data['overall_average_percentage']),
            'expected' => $pct($data['expected_performance_percent']),
            'gap' => $data['overall_gap'] === null ? 'N/A' : number_format((float) $data['overall_gap'], 1) . ' pts',
            'status' => $label($data['overall_status']),
            'questions' => array_map(fn ($q) => [
                'label' => 'Q' . $q['question_number'],
                'average' => $pct($q['average_percentage']),
                'responses' => $q['response_count'] . ' / ' . $q['submission_count'],
                'gap' => $q['performance_gap'] === null ? '—' : number_format((float) $q['performance_gap'], 1),
                'status' => $label($q['performance_status']),
                'difficulty' => $q['difficulty_level'] ? ucfirst($q['difficulty_level']) : '—',
            ], $data['questions']),
            'topics' => array_map(fn ($t) => ['label' => $t['topic'], 'average' => $pct($t['average_percentage']), 'status' => $label($t['performance_status'])], $data['topics']),
            'learning_outcomes' => array_map(fn ($lo) => ['label' => $lo['lo_code'], 'average' => $pct($lo['average_percentage']), 'status' => $label($lo['performance_status'])], $data['learning_outcomes']),
            'gap_areas' => array_map(fn ($g) => $g['label'] . ' — ' . $pct($g['average_percentage']) . ' (gap ' . number_format((float) $g['performance_gap'], 1) . ' pts)', array_slice($data['summary']['gap_areas'] ?? [], 0, 6)),
            'strong_areas' => array_map(fn ($s) => $s['label'] . ' — ' . $pct($s['average_percentage']), array_slice($data['summary']['strong_areas'] ?? [], 0, 6)),
            'limitations' => $data['limitations'],
        ];
    }

    /**
     * STEP 31: course-level CO/PO mapping analysis, only when a completed run exists.
     */
    protected function buildCoPoSection(Assessment $assessment): ?array
    {
        $run = \App\Models\CoPoMappingAnalysisRun::where('course_id', $assessment->course_id)->where('is_current', true)->with('findings')->first();
        if (!$run || !$run->isCompleted()) {
            return null;
        }
        $service = app(\App\Services\CoPoMappingValidatorService::class);
        $data = $service->present($run, true);

        return [
            'program' => $run->program?->code ? $run->program->code . ' — ' . $run->program->name : null,
            'analyzed_at' => $run->analyzed_at?->format('Y-m-d H:i'),
            'is_stale' => $data['is_stale'],
            'summary' => $data['summary'],
            'matrix' => $data['matrix'],
            'co_coverage' => $data['co_coverage'],
            'po_evidence' => $data['po_evidence'],
            'findings' => array_slice($data['findings'], 0, 12),
            'disclaimer' => $data['disclaimer'],
        ];
    }

    /**
     * Generate authoritative PDF for the assessment report and persist record.
     */
    public function generatePdf(Assessment $assessment, User $user): AssessmentReport
    {
        $data = $this->buildReportData($assessment);

        $uuid = (string) Str::uuid();
        $safeCode = preg_replace('/[^A-Za-z0-9_\-]/', '_', $assessment->course->course_code);
        $fileName = "FacultyLens_Report_{$safeCode}_Assessment_{$assessment->id}_{$uuid}.pdf";
        $filePath = "reports/{$assessment->id}/{$uuid}.pdf";

        // Render PDF with dompdf
        $pdf = Pdf::loadView('reports.assessment-pdf', ['data' => $data]);
        $pdf->setPaper('a4', 'portrait');
        $pdf->setOptions([
            'isHtml5ParserEnabled' => true,
            'isRemoteEnabled' => true,
            'defaultFont' => 'Helvetica',
        ]);

        $pdfBinary = $pdf->output();

        // Store PDF in private storage (storage/app/private/reports/{assessment_id}/{uuid}.pdf)
        Storage::disk('local')->put($filePath, $pdfBinary);

        $fileSize = strlen($pdfBinary);

        // Record in assessment_reports table
        $reportRecord = AssessmentReport::create([
            'assessment_id' => $assessment->id,
            'analysis_report_id' => $assessment->latestAnalysisReport->id,
            'report_uuid' => $uuid,
            'file_name' => $fileName,
            'file_path' => $filePath,
            'file_size' => $fileSize,
            'generation_status' => 'completed',
            'is_shareable' => false,
            'share_token' => null,
            'generated_at' => now(),
            'created_by' => $user->id,
            'metadata' => [
                'overall_score' => $data['overall_quality']['score'],
                'rating' => $data['overall_quality']['rating'],
                'course_code' => $data['assessment']['course_code'],
                'assessment_title' => $data['assessment']['title'],
            ],
        ]);

        return $reportRecord;
    }

    /**
     * Enable sharing for a report and generate a secure token.
     */
    public function shareReport(AssessmentReport $report): string
    {
        $token = Str::random(40);
        $report->update([
            'is_shareable' => true,
            'share_token' => $token,
            'shared_at' => now(),
            'revoked_at' => null,
        ]);

        return $token;
    }

    /**
     * Revoke public share link for a report.
     */
    public function revokeShare(AssessmentReport $report): void
    {
        $report->update([
            'is_shareable' => false,
            'revoked_at' => now(),
        ]);
    }
}
