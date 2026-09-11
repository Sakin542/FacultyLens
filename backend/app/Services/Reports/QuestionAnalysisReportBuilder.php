<?php

namespace App\Services\Reports;

use App\Models\LearningOutcome;
use App\Models\Question;
use App\Models\QuestionLearningOutcomeAlignment;
use App\Models\QuestionSimilarityMatch;

/** Question Analysis Report — question-level metadata (faculty first, AI fallback) with alignment and similarity findings. */
class QuestionAnalysisReportBuilder extends AbstractReportBuilder
{
    public function build(ReportContext $ctx): array
    {
        $assessment = $ctx->assessment->loadMissing('course');
        $version = $ctx->version;
        $analysis = $this->analysisFor($ctx);

        $alignments = $analysis ? QuestionLearningOutcomeAlignment::where('analysis_report_id', $analysis->id)->with('learningOutcome:id,code')->get()->groupBy('question_id') : collect();
        $similar = $analysis ? QuestionSimilarityMatch::where('analysis_report_id', $analysis->id)->selectRaw('current_question_id, MAX(similarity_score) AS s, COUNT(*) AS c')->groupBy('current_question_id')->get()->keyBy('current_question_id') : collect();

        if ($version) {
            $los = LearningOutcome::whereIn('id', $version->questions()->whereNotNull('learning_outcome_id')->pluck('learning_outcome_id'))->pluck('code', 'id');
            $rows = $version->questions()->orderBy('sort_order')->orderBy('question_number')->get()->map(function ($q) use ($los, $alignments, $similar, $assessment, $version) {
                $a = $alignments->get($q->original_question_id, collect());
                $s = $similar->get($q->original_question_id);

                return ['course_code' => $assessment->course->course_code, 'assessment' => $assessment->title, 'version' => $version->version_label, 'question' => $q->question_number, 'text' => mb_substr((string) $q->question_text, 0, 200),
                    'type' => $q->question_type, 'marks' => (float) $q->marks, 'difficulty' => $q->difficulty_level ? strtoupper($q->difficulty_level) : null, 'cognitive_level' => $q->cognitive_level ? strtoupper($q->cognitive_level) : null,
                    'co' => $q->learning_outcome_id ? ($los[$q->learning_outcome_id] ?? null) : null, 'topic' => $q->topic,
                    'alignment' => $a->isEmpty() ? ($analysis ? 'NOT_ANALYZED' : null) : $a->sortByDesc('similarity_score')->first()->alignment,
                    'alignment_score' => $a->isEmpty() ? null : round((float) $a->max('similarity_score'), 2),
                    'max_similarity' => $s ? round((float) $s->s, 2) : null, 'similar_matches' => $s ? (int) $s->c : ($analysis ? 0 : null)];
            })->values()->all();
        } else {
            $rows = Question::where('assessment_id', $assessment->id)->with('learningOutcome:id,code')->orderBy('question_number')->get()->map(function ($q) use ($alignments, $similar, $analysis, $assessment) {
                $a = $alignments->get($q->id, collect());
                $s = $similar->get($q->id);
                $difficulty = $q->difficulty_level ?: $q->ai_difficulty_level;
                $cognitive = $q->cognitive_level ?: $q->ai_cognitive_level;

                return ['course_code' => $assessment->course->course_code, 'assessment' => $assessment->title, 'version' => null, 'question' => $q->question_number, 'text' => mb_substr((string) $q->question_text, 0, 200),
                    'type' => $q->question_type, 'marks' => (float) $q->marks, 'difficulty' => $difficulty ? strtoupper($difficulty) : null, 'cognitive_level' => $cognitive ? strtoupper($cognitive) : null,
                    'co' => $q->learningOutcome?->code, 'topic' => is_array($q->ai_topics) ? implode(', ', $q->ai_topics) : null,
                    'alignment' => $a->isEmpty() ? ($analysis ? 'NOT_ANALYZED' : null) : $a->sortByDesc('similarity_score')->first()->alignment,
                    'alignment_score' => $a->isEmpty() ? null : round((float) $a->max('similarity_score'), 2),
                    'max_similarity' => $s ? round((float) $s->s, 2) : null, 'similar_matches' => $s ? (int) $s->c : ($analysis ? 0 : null)];
            })->values()->all();
        }
        $n = count($rows);
        $marks = array_sum(array_column($rows, 'marks'));
        $summary = [
            $this->kv('Assessment', $assessment->title),
            $this->kv('Version', $version?->version_label ?? 'Live questions'),
            $this->kv('Questions', $n),
            $this->kv('Total Marks', $marks),
            $this->kv('Mapped to a CO', count(array_filter($rows, fn ($r) => $r['co'] !== null))),
            $this->kv('Analysis', $analysis ? 'Completed ' . $analysis->analyzed_at?->toDateString() : 'Not analyzed'),
        ];
        $warnings = [];
        if (!$analysis) {
            $warnings[] = 'No completed analysis: alignment and similarity columns are unavailable.';
        }
        if ($n === 0) {
            $warnings[] = 'The assessment has no questions.';
        }

        return $this->document($ctx, $summary, [], [
            $this->table('questions', 'Question Analysis', ['course_code' => 'Course', 'assessment' => 'Assessment', 'version' => 'Version', 'question' => 'Question', 'text' => 'Text', 'type' => 'Type', 'marks' => 'Marks', 'difficulty' => 'Difficulty', 'cognitive_level' => 'Cognitive Level', 'co' => 'CO', 'topic' => 'Topic',
                'alignment' => 'LO Alignment', 'alignment_score' => 'Alignment Score', 'max_similarity' => 'Max Similarity', 'similar_matches' => 'Similar Matches'], $rows),
        ], $warnings, $analysis?->analyzed_at?->toISOString());
    }
}
