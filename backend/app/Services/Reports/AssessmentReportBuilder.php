<?php

namespace App\Services\Reports;

use App\Models\AnalysisReport;
use App\Models\LearningOutcome;
use App\Models\Question;
use App\Models\QuestionLearningOutcomeAlignment;
use App\Models\QuestionSimilarityMatch;
use App\Models\Recommendation;
use App\Services\Analytics\OutcomeAnalyticsService;
use App\Services\AssessmentBlueprintService;
use App\Services\AssessmentReportService;
use App\Services\AssessmentVersionService;
use Illuminate\Support\Collection;

/**
 * Assessment Report — one assessment (live questions + current STEP 13 analysis) or one frozen
 * assessment version (snapshot questions + the analysis attached to that version). A report for
 * v3 is never reinterpreted with v4 data.
 */
class AssessmentReportBuilder extends AbstractReportBuilder
{
    public function __construct(
        protected AssessmentReportService $reports,
        protected AssessmentVersionService $versions,
        protected AssessmentBlueprintService $blueprints,
    ) {}

    public function build(ReportContext $ctx): array
    {
        $assessment = $ctx->assessment->loadMissing('course');
        $version = $ctx->version;
        $questions = $this->questionRows($ctx);
        $analysis = $this->analysisFor($ctx);
        $warnings = [];

        $totalMarks = $version ? (float) $version->total_marks : (float) $assessment->total_marks;
        $questionCount = $version ? (int) $version->question_count : $questions->count();
        $blueprint = $this->blueprints->compliance($assessment);
        $currentVersion = $version ? $this->versions->summary($version) : $this->versions->reportSection($assessment);

        $summary = [
            $this->kv('Assessment', $assessment->title),
            $this->kv('Course', $assessment->course->course_code.' — '.$assessment->course->course_name),
            $this->kv('Assessment Type', $this->humanize($version?->assessment_type ?? $assessment->type)),
            $this->kv('Version', $currentVersion['version_label'] ?? 'No version recorded'),
            $this->kv('Version Status', $currentVersion['status'] ?? 'N/A'),
            $this->kv('Date', $assessment->assessment_date?->toDateString() ?? 'N/A'),
            $this->kv('Total Marks', $totalMarks),
            $this->kv('Duration (minutes)', $version?->duration_minutes ?? $assessment->duration_minutes ?? 'N/A'),
            $this->kv('Question Count', $questionCount),
            $this->kv('Blueprint Status', $blueprint ? "v{$blueprint['version']} {$blueprint['status']} ({$blueprint['validation_status']})" : 'No blueprint'),
            $this->kv('Quality Rating', $analysis ? $this->reports->getRatingLabel((float) $analysis->overall_score) : 'Not analyzed'),
            $this->kv('Quality Score', $analysis ? round((float) $analysis->overall_score, 2) : 'Not analyzed'),
        ];
        if (! $analysis) {
            $warnings[] = 'No completed AI analysis exists for this '.($version ? 'version' : 'assessment').'; quality, alignment, similarity and recommendation sections are unavailable.';
        }

        $tables = [
            $this->table('questions', 'Question Summary', ['number' => '#', 'text' => 'Question', 'type' => 'Type', 'marks' => 'Marks', 'difficulty' => 'Difficulty', 'cognitive_level' => 'Cognitive Level', 'co' => 'CO', 'topic' => 'Topic'], $questions->all()),
            $this->distributionTable('difficulty', 'Difficulty Distribution', $questions, 'difficulty', ['easy', 'medium', 'hard'], (array) config('analytics.difficulty_targets')),
            $this->distributionTable('cognitive', 'Bloom Distribution', $questions, 'cognitive_level', ['Remember', 'Understand', 'Apply', 'Analyze', 'Evaluate', 'Create']),
            $this->coCoverageTable($ctx, $questions, $analysis),
            $this->poTable($ctx),
            $this->topicTable($questions),
            $this->similarityTable($analysis),
            $this->recommendationTable($analysis),
        ];

        $sections = [];
        if ($analysis) {
            $sections[] = $this->section('quality', 'Quality Dimensions (STEP 13)', [
                'Overall' => $this->na(round((float) $analysis->overall_score, 2)),
                'Topic Coverage' => $this->na($analysis->topic_coverage_score !== null ? round((float) $analysis->topic_coverage_score, 2) : null),
                'LO Alignment' => $this->na($analysis->learning_outcome_alignment_score !== null ? round((float) $analysis->learning_outcome_alignment_score, 2) : null),
                'Difficulty Balance' => $this->na($analysis->difficulty_balance_score !== null ? round((float) $analysis->difficulty_balance_score, 2) : null),
                'Cognitive Balance' => $this->na($analysis->cognitive_level_balance_score !== null ? round((float) $analysis->cognitive_level_balance_score, 2) : null),
                'Similarity' => $this->na($analysis->similarity_score !== null ? round((float) $analysis->similarity_score, 2) : null),
                'Analyzed At' => $analysis->analyzed_at?->toDateTimeString() ?? 'N/A',
            ]);
        }
        if ($blueprint) {
            $sections[] = $this->section('blueprint', 'Blueprint Compliance (STEP 37)', ['Blueprint Version' => $blueprint['version'], 'Status' => $blueprint['status'], 'Validation' => $blueprint['validation_status'] ?? 'N/A', 'Compliance' => $this->na($blueprint['compliance_percent'], '%', 'No questions to compare')]);
        }

        return $this->document($ctx, $summary, $sections, $tables, $warnings, $analysis?->analyzed_at?->toISOString());
    }

    /** Snapshot questions for a version, live questions otherwise (faculty metadata first, AI as fallback). */
    protected function questionRows(ReportContext $ctx): Collection
    {
        if ($ctx->version) {
            $los = LearningOutcome::whereIn('id', $ctx->version->questions()->whereNotNull('learning_outcome_id')->pluck('learning_outcome_id'))->pluck('code', 'id');

            return $ctx->version->questions()->orderBy('sort_order')->orderBy('question_number')->get()->map(fn ($q) => [
                'id' => $q->id, 'number' => $q->question_number, 'text' => mb_substr((string) $q->question_text, 0, 160), 'type' => $q->question_type, 'marks' => (float) $q->marks,
                'difficulty' => $q->difficulty_level ? strtolower($q->difficulty_level) : null, 'cognitive_level' => $q->cognitive_level ? ucfirst(strtolower($q->cognitive_level)) : null,
                'co' => $q->learning_outcome_id ? ($los[$q->learning_outcome_id] ?? null) : null, 'topic' => $q->topic, 'original_question_id' => $q->original_question_id,
            ])->values();
        }

        return Question::where('assessment_id', $ctx->assessment->id)->with('learningOutcome:id,code')->orderBy('question_number')->get()->map(fn ($q) => [
            'id' => $q->id, 'number' => $q->question_number, 'text' => mb_substr((string) $q->question_text, 0, 160), 'type' => $q->question_type, 'marks' => (float) $q->marks,
            'difficulty' => strtolower((string) ($q->difficulty_level ?: $q->ai_difficulty_level)) ?: null, 'cognitive_level' => ($q->cognitive_level ?: $q->ai_cognitive_level) ? ucfirst(strtolower((string) ($q->cognitive_level ?: $q->ai_cognitive_level))) : null,
            'co' => $q->learningOutcome?->code, 'topic' => is_array($q->ai_topics) ? implode(', ', $q->ai_topics) : null, 'original_question_id' => $q->id,
        ])->values();
    }

    protected function distributionTable(string $key, string $title, Collection $questions, string $field, array $levels, array $targets = []): ?array
    {
        $n = $questions->count();
        $rows = [];
        foreach ($levels as $level) {
            $count = $questions->filter(fn ($q) => strtolower((string) $q[$field]) === strtolower($level))->count();
            $marks = $questions->filter(fn ($q) => strtolower((string) $q[$field]) === strtolower($level))->sum('marks');
            $pct = $n ? round($count / $n * 100, 1) : null;
            $row = ['level' => ucfirst($level), 'count' => $count, 'marks' => $marks, 'percentage' => $pct];
            if ($targets) {
                $t = $targets[strtolower($level)] ?? null;
                $row['target_percentage'] = $t;
                $row['difference'] = $pct === null || $t === null ? null : round($pct - $t, 1);
            }
            $rows[] = $row;
        }
        $unclassified = $n - array_sum(array_column($rows, 'count'));
        if ($unclassified > 0) {
            $rows[] = ['level' => 'Unclassified', 'count' => $unclassified, 'marks' => null, 'percentage' => $n ? round($unclassified / $n * 100, 1) : null] + ($targets ? ['target_percentage' => null, 'difference' => null] : []);
        }
        $columns = ['level' => 'Level', 'count' => 'Questions', 'marks' => 'Marks', 'percentage' => 'Share %'];
        if ($targets) {
            $columns += ['target_percentage' => 'Target %', 'difference' => 'Difference'];
        }

        return $this->table($key, $title, $columns, $rows);
    }

    protected function coCoverageTable(ReportContext $ctx, Collection $questions, ?AnalysisReport $analysis): ?array
    {
        $los = LearningOutcome::where('course_id', $ctx->course->id)->orderBy('sort_order')->orderBy('code')->get();
        if ($los->isEmpty()) {
            return null;
        }
        $agg = $analysis
            ? QuestionLearningOutcomeAlignment::where('analysis_report_id', $analysis->id)
                ->selectRaw("learning_outcome_id, COUNT(DISTINCT question_id) AS questions,
                    COUNT(DISTINCT CASE WHEN alignment = 'STRONG_ALIGNMENT' THEN question_id END) AS strong,
                    COUNT(DISTINCT CASE WHEN alignment = 'WEAK_ALIGNMENT' THEN question_id END) AS weak,
                    COUNT(DISTINCT CASE WHEN alignment = 'NOT_ALIGNED' THEN question_id END) AS not_aligned")
                ->groupBy('learning_outcome_id')->get()->keyBy('learning_outcome_id')
            : collect();
        $totalMarks = (float) $questions->sum('marks');
        $rows = $los->map(function ($lo) use ($questions, $agg, $totalMarks) {
            $mapped = $questions->where('co', $lo->code);
            $a = $agg[$lo->id] ?? null;

            return ['code' => $lo->code, 'description' => mb_substr((string) $lo->description, 0, 120), 'mapped_questions' => $mapped->count(), 'marks' => (float) $mapped->sum('marks'),
                'marks_share' => $totalMarks ? round($mapped->sum('marks') / $totalMarks * 100, 1) : null,
                'strong' => $a ? (int) $a->strong : null, 'weak' => $a ? (int) $a->weak : null, 'not_aligned' => $a ? (int) $a->not_aligned : null,
                'status' => $mapped->count() === 0 ? 'NOT_ASSESSED' : ($a ? ((int) $a->strong > 0 ? 'COVERED' : ((int) $a->weak > 0 ? 'WEAK' : 'NOT_ALIGNED')) : 'MAPPED (not analyzed)')];
        })->all();

        return $this->table('co_coverage', 'CO/LO Coverage Evidence', ['code' => 'CO', 'description' => 'Description', 'mapped_questions' => 'Mapped Questions', 'marks' => 'Marks', 'marks_share' => 'Marks %', 'strong' => 'Strong', 'weak' => 'Weak', 'not_aligned' => 'Not Aligned', 'status' => 'Status'], $rows,
            'Coverage is evidence from question mapping and AI alignment analysis; it is not an accreditation compliance statement.');
    }

    protected function poTable(ReportContext $ctx): ?array
    {
        $po = app(OutcomeAnalyticsService::class)->programOutcomes([$ctx->course->id]);
        if (! ($po['configured'] ?? false)) {
            return $this->table('po_coverage', 'PO Coverage', ['message' => 'Status'], [['message' => 'PO Mapping is not configured for this course.']]);
        }

        return $this->table('po_coverage', 'PO Coverage', ['code' => 'PO', 'title' => 'Title', 'mapped_cos' => 'Mapped COs', 'mapped_questions' => 'Mapped Questions', 'evidence_percent' => 'Evidence %', 'status' => 'Status'], $po['program_outcomes']);
    }

    protected function topicTable(Collection $questions): ?array
    {
        $byTopic = [];
        $total = (float) $questions->sum('marks');
        foreach ($questions as $q) {
            foreach (array_filter(array_map('trim', explode(',', (string) ($q['topic'] ?? '')))) as $t) {
                $byTopic[$t] ??= ['topic' => $t, 'questions' => 0, 'marks' => 0.0];
                $byTopic[$t]['questions']++;
                $byTopic[$t]['marks'] += (float) $q['marks'];
            }
        }
        if ($byTopic === []) {
            return null;
        }
        $rows = array_map(fn ($t) => $t + ['marks_share' => $total ? round($t['marks'] / $total * 100, 1) : null], array_values($byTopic));
        usort($rows, fn ($a, $b) => $b['marks'] <=> $a['marks']);

        return $this->table('topics', 'Topic Coverage', ['topic' => 'Topic', 'questions' => 'Questions', 'marks' => 'Marks', 'marks_share' => 'Marks %'], $rows);
    }

    protected function similarityTable(?AnalysisReport $analysis): ?array
    {
        if (! $analysis) {
            return null;
        }
        $rows = QuestionSimilarityMatch::where('analysis_report_id', $analysis->id)->with(['currentQuestion:id,question_number', 'previousQuestion:id,question_text,source'])
            ->orderByDesc('similarity_score')->limit(200)->get()->map(fn ($m) => ['question' => $m->currentQuestion?->question_number, 'similarity' => round((float) $m->similarity_score, 2), 'status' => $m->similarity_status,
                'previous_question' => mb_substr((string) $m->previousQuestion?->question_text, 0, 120), 'source' => $m->previousQuestion?->source])->all();

        return $this->table('similarity', 'Similarity Findings (STEP 12)', ['question' => 'Question #', 'similarity' => 'Similarity', 'status' => 'Status', 'previous_question' => 'Previous Question', 'source' => 'Source'], $rows,
            $rows === [] ? 'No similar previous questions were found.' : 'Similarity is an indicator for faculty review, not a plagiarism verdict.');
    }

    protected function recommendationTable(?AnalysisReport $analysis): ?array
    {
        if (! $analysis) {
            return null;
        }
        $rows = Recommendation::where('analysis_report_id', $analysis->id)->orderByRaw("CASE LOWER(priority) WHEN 'high' THEN 0 WHEN 'medium' THEN 1 ELSE 2 END")->get()
            ->map(fn ($r) => ['priority' => strtoupper((string) $r->priority), 'category' => $r->category, 'title' => $r->title, 'recommendation' => $r->recommendation, 'status' => strtoupper((string) $r->status)])->all();

        return $this->table('recommendations', 'Recommendations', ['priority' => 'Priority', 'category' => 'Category', 'title' => 'Title', 'recommendation' => 'Recommendation', 'status' => 'Faculty Decision'], $rows,
            'Recommendations are assistive; faculty decide whether to act on them.');
    }
}
