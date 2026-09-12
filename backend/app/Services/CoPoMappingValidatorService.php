<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\CoPoMapping;
use App\Models\CoPoMappingAnalysisRun;
use App\Models\CoPoMappingFinding;
use App\Models\Course;
use App\Models\LearningOutcome;
use App\Models\ProgramOutcome;
use App\Models\Question;
use App\Models\QuestionCoMapping;
use App\Models\QuestionLearningOutcomeAlignment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * STEP 31: CO/PO Mapping Validator.
 *
 * Deterministic analysis of Course Outcomes (existing learning_outcomes), faculty-defined CO->PO
 * mappings, confirmed question->CO mappings, assessment coverage, PO evidence and STEP 30 student
 * performance. Produces review signals with cautious recommendations. It never changes mappings,
 * never treats AI suggestions as official, and never makes accreditation claims.
 *
 * Formulas (documented for transparency):
 *   CO coverage %        = marks attributed to CO / total course assessment marks × 100
 *                          (a question mapped to several COs shares its marks equally)
 *   mapping density %    = non-zero CO×PO cells / (COs × POs) × 100
 *   PO contribution %    = Σ over mapped COs (CO coverage × level weight)   [weights 1/3, 2/3, 1]
 *   PO assessment evid.% = Σ coverage of COs mapped to the PO with level > 0
 *   CO performance %     = Σ finalized marks / Σ maximum marks for the CO's confirmed questions (STEP 30)
 */
class CoPoMappingValidatorService
{
    public const STATUS_CONFLICT = 409;
    public const STATUS_VALIDATION = 422;

    public const DISCLAIMER = 'CO/PO mapping analysis provides evidence and review signals based on configured course outcomes, program outcomes, assessment mappings, and available performance data. It does not constitute an accreditation decision or guarantee institutional compliance. Faculty and authorized academic personnel remain responsible for final curriculum and outcome-mapping decisions.';

    protected const BLOOM_ORDER = ['remember' => 1, 'understand' => 2, 'apply' => 3, 'analyze' => 4, 'evaluate' => 5, 'create' => 6];

    public function __construct(
        protected AuditLogService $auditLogService,
        protected StudentPerformanceService $performanceService,
    ) {}

    // ------------------------------------------------------------------ config

    public function thresholds(): array
    {
        return [
            'co_min_coverage_percent' => (float) config('co_po.co_min_coverage_percent', 5),
            'co_concentration_percent' => (float) config('co_po.co_concentration_percent', 60),
            'po_evidence_min_percent' => (float) config('co_po.po_evidence_min_percent', 10),
            'mapping_density_review_percent' => (float) config('co_po.mapping_density_review_percent', 90),
            'cognitive_mismatch_percent' => (float) config('co_po.cognitive_mismatch_percent', 60),
            'mapping_levels' => config('co_po.mapping_levels'),
            'expected_performance_percent' => $this->performanceService->expected(),
        ];
    }

    // ------------------------------------------------------------- overview

    /**
     * Live (uncached-by-run) view of COs, POs, mappings, questions and the current analysis run.
     */
    public function overview(Course $course): array
    {
        $ctx = $this->context($course);
        $run = $this->currentRun($course);

        return [
            'course' => ['id' => $course->id, 'course_code' => $course->course_code, 'course_name' => $course->course_name],
            'program' => $ctx['program'] ? ['id' => $ctx['program']->id, 'code' => $ctx['program']->code, 'name' => $ctx['program']->name] : null,
            'course_outcomes' => $ctx['cos']->map(fn ($lo) => $this->presentCo($lo))->values()->all(),
            'program_outcomes' => $ctx['pos']->map(fn ($po) => $this->presentPo($po))->values()->all(),
            'mappings' => $ctx['mappings']->map(fn ($m) => $this->presentMapping($m))->values()->all(),
            'summary' => $this->liveSummary($ctx),
            'thresholds' => $this->thresholds(),
            'current_run' => $run ? $this->present($run, false) : null,
            'disclaimer' => self::DISCLAIMER,
        ];
    }

    public function matrix(Course $course): array
    {
        return $this->buildMatrix($this->context($course));
    }

    public function currentRun(Course $course): ?CoPoMappingAnalysisRun
    {
        return CoPoMappingAnalysisRun::where('course_id', $course->id)->current()->first();
    }

    // -------------------------------------------------------------- analysis

    /**
     * Run (synchronously — the calculation is deterministic and small) and persist a new analysis.
     *
     * @throws CoPoMappingException
     */
    public function analyze(Course $course, User $user, bool $force = false): CoPoMappingAnalysisRun
    {
        $ctx = $this->context($course);
        if ($ctx['cos']->isEmpty()) {
            throw new CoPoMappingException('This course has no course outcomes yet. Add learning outcomes before analyzing CO/PO mapping.', self::STATUS_VALIDATION);
        }

        $current = $this->currentRun($course);
        $version = $this->mappingVersion($ctx);
        if ($current && !$force && $current->isCompleted() && $current->mapping_version === $version) {
            throw new CoPoMappingException('A mapping analysis for the current mappings already exists. Use regenerate to run it again.', self::STATUS_CONFLICT);
        }

        $run = DB::transaction(function () use ($course, $user, $ctx) {
            CoPoMappingAnalysisRun::where('course_id', $course->id)->where('is_current', true)->update(['is_current' => false]);
            return CoPoMappingAnalysisRun::create([
                'course_id' => $course->id,
                'program_id' => $ctx['program']?->id,
                'status' => CoPoMappingAnalysisRun::STATUS_PROCESSING,
                'is_current' => true,
                'requested_by' => $user->id,
                'thresholds' => $this->thresholds(),
            ]);
        });

        try {
            $result = $this->calculate($ctx);

            DB::transaction(function () use ($run, $result, $version) {
                foreach ($result['findings'] as $f) {
                    CoPoMappingFinding::create(['analysis_run_id' => $run->id] + $f);
                }
                $run->update([
                    'status' => CoPoMappingAnalysisRun::STATUS_COMPLETED,
                    'mapping_version' => $version,
                    'summary' => $result['summary'],
                    'matrix' => $result['matrix'],
                    'co_coverage' => $result['co_coverage'],
                    'po_evidence' => $result['po_evidence'],
                    'analyzed_at' => now(),
                ]);
            });

            self::invalidateCache($course->id);

            $this->auditLogService->log($current ? 'CO_PO_ANALYSIS_REGENERATED' : 'CO_PO_ANALYSIS_GENERATED', $run, $run->id, [
                'course_id' => $course->id,
                'program_id' => $ctx['program']?->id,
                'findings' => count($result['findings']),
                'high_findings' => count(array_filter($result['findings'], fn ($f) => $f['severity'] === 'HIGH')),
                'mapping_density' => $result['summary']['mapping_density_percent'],
            ], $user);
        } catch (\Throwable $e) {
            Log::error('CO/PO analysis failed for course ' . $course->id . ': ' . get_class($e) . ' ' . $e->getMessage());
            $run->update(['status' => CoPoMappingAnalysisRun::STATUS_FAILED, 'error_message' => 'The mapping analysis could not be completed. Please try again.']);
        }

        return $run->fresh(['findings']);
    }

    /**
     * Pure calculation. Returns matrix, CO coverage/performance, PO evidence, findings and summary.
     */
    public function calculate(array $ctx): array
    {
        $t = $this->thresholds();
        $cos = $ctx['cos'];
        $pos = $ctx['pos'];
        $questions = $ctx['questions'];
        $confirmed = $ctx['confirmed']; // question_id => [lo_id, ...]
        $totalMarks = (float) $questions->sum(fn ($q) => (float) $q->marks);

        // ---- CO coverage & cognitive profile
        $coMarks = [];
        $coQuestions = [];
        $coCognitive = [];
        $unmappedQuestions = [];
        foreach ($questions as $q) {
            $los = array_values(array_filter($confirmed[$q->id] ?? [], fn ($id) => $cos->has($id)));
            if (!$los) {
                $unmappedQuestions[] = $q;
                continue;
            }
            $share = (float) $q->marks / count($los);
            $level = strtolower((string) ($q->cognitive_level ?: $q->ai_cognitive_level ?: ''));
            foreach ($los as $loId) {
                $coMarks[$loId] = ($coMarks[$loId] ?? 0) + $share;
                $coQuestions[$loId][] = $q->id;
                $coCognitive[$loId][$level] = ($coCognitive[$loId][$level] ?? 0) + $share;
            }
        }

        $performance = $this->coPerformance($ctx, $coQuestions);

        $coCoverage = [];
        foreach ($cos as $lo) {
            $marks = round($coMarks[$lo->id] ?? 0, 2);
            $coverage = $totalMarks > 0 ? round($marks / $totalMarks * 100, 2) : 0.0;
            $perf = $performance[$lo->id] ?? null;
            $coCoverage[] = [
                'learning_outcome_id' => $lo->id,
                'code' => $lo->code,
                'display_code' => $this->coCode($lo),
                'description' => $lo->description,
                'cognitive_level' => $lo->cognitive_level,
                'question_count' => count($coQuestions[$lo->id] ?? []),
                'question_ids' => array_values($coQuestions[$lo->id] ?? []),
                'mapped_marks' => $marks,
                'coverage_percent' => $coverage,
                'coverage_status' => $marks <= 0 ? 'NOT_ASSESSED' : ($coverage < $t['co_min_coverage_percent'] ? 'LOW_COVERAGE' : ($coverage >= $t['co_concentration_percent'] ? 'CONCENTRATED' : 'ASSESSED')),
                'po_mapping_count' => $ctx['mappings']->where('learning_outcome_id', $lo->id)->where('mapping_level', '>', 0)->count(),
                'performance_percent' => $perf['average_percentage'] ?? null,
                'performance_gap' => $perf['performance_gap'] ?? null,
                'performance_status' => $perf['performance_status'] ?? 'INSUFFICIENT_DATA',
                'response_count' => $perf['response_count'] ?? 0,
                'status' => $this->coStatus($marks, $coverage, $perf, $t),
            ];
        }
        $coByLo = collect($coCoverage)->keyBy('learning_outcome_id');

        // ---- Matrix & density
        $matrix = $this->buildMatrix($ctx);

        // ---- PO evidence
        $weights = config('co_po.mapping_weights');
        $poEvidence = [];
        foreach ($pos as $po) {
            $rows = $ctx['mappings']->where('program_outcome_id', $po->id)->where('mapping_level', '>', 0);
            $contribution = 0.0;
            $evidencePct = 0.0;
            $maxLevel = 0;
            $perfSum = 0.0;
            $perfMax = 0.0;
            $qids = [];
            foreach ($rows as $m) {
                $co = $coByLo->get($m->learning_outcome_id);
                if (!$co) {
                    continue;
                }
                $contribution += $co['coverage_percent'] * ($weights[$m->mapping_level] ?? 0);
                $evidencePct += $co['coverage_percent'];
                $maxLevel = max($maxLevel, (int) $m->mapping_level);
                $qids = array_merge($qids, $co['question_ids']);
                $p = $performance[$m->learning_outcome_id] ?? null;
                if ($p && $p['sum_max'] > 0) {
                    $perfSum += $p['sum_marks'];
                    $perfMax += $p['sum_max'];
                }
            }
            $qids = array_values(array_unique($qids));
            $studentPct = $perfMax > 0 ? round($perfSum / $perfMax * 100, 2) : null;
            $poEvidence[] = [
                'program_outcome_id' => $po->id,
                'code' => $po->code,
                'title' => $po->title,
                'mapped_co_count' => $rows->count(),
                'mapped_cos' => $rows->map(fn ($m) => ['learning_outcome_id' => $m->learning_outcome_id, 'code' => $this->coCode($cos->get($m->learning_outcome_id)), 'level' => (int) $m->mapping_level, 'level_label' => CoPoMapping::levelLabel((int) $m->mapping_level)])->values()->all(),
                'co_evidence' => CoPoMapping::levelLabel($maxLevel),
                'contribution_percent' => round($contribution, 2),
                'assessment_evidence_percent' => round(min($evidencePct, 100), 2),
                'question_ids' => $qids,
                'student_performance_percent' => $studentPct,
                'evidence_status' => $rows->isEmpty() ? 'NOT_MAPPED' : ($evidencePct >= $t['po_evidence_min_percent'] ? 'ASSESSED' : 'LIMITED_EVIDENCE'),
                'status' => $rows->isEmpty() ? 'NOT_MAPPED' : ($evidencePct < $t['po_evidence_min_percent'] ? 'LIMITED_EVIDENCE' : ($studentPct !== null && $this->performanceService->gap($studentPct) >= (float) config('performance.gap_moderate_threshold', 10) ? 'REVIEW' : 'EVIDENCE_AVAILABLE')),
            ];
        }

        // ---- Findings
        $findings = $this->findings($ctx, $coCoverage, $poEvidence, $matrix, $unmappedQuestions, $coCognitive, $coMarks, $t);

        $summary = [
            'co_count' => $cos->count(),
            'po_count' => $pos->count(),
            'active_mapping_count' => $matrix['active_mappings'],
            'possible_mapping_count' => $matrix['possible_mappings'],
            'mapping_density_percent' => $matrix['density_percent'],
            'question_count' => $questions->count(),
            'questions_mapped' => $questions->count() - count($unmappedQuestions),
            'total_marks' => round($totalMarks, 2),
            'cos_with_evidence' => count(array_filter($coCoverage, fn ($c) => $c['mapped_marks'] > 0)),
            'cos_with_po_mapping' => count(array_filter($coCoverage, fn ($c) => $c['po_mapping_count'] > 0)),
            'pos_with_evidence' => count(array_filter($poEvidence, fn ($p) => $p['evidence_status'] === 'ASSESSED')),
            'pos_mapped' => count(array_filter($poEvidence, fn ($p) => $p['mapped_co_count'] > 0)),
            'co_coverage_percent' => $cos->count() ? round(count(array_filter($coCoverage, fn ($c) => $c['mapped_marks'] > 0)) / $cos->count() * 100, 2) : 0,
            'po_evidence_percent' => $pos->count() ? round(count(array_filter($poEvidence, fn ($p) => $p['evidence_status'] === 'ASSESSED')) / $pos->count() * 100, 2) : 0,
            'question_mapping_percent' => $questions->count() ? round(($questions->count() - count($unmappedQuestions)) / $questions->count() * 100, 2) : 0,
            'finding_counts' => array_count_values(array_column($findings, 'severity')) + ['HIGH' => 0, 'MEDIUM' => 0, 'LOW' => 0, 'INFO' => 0],
            'validation_checks' => $this->checks($coCoverage, $poEvidence, $unmappedQuestions, $questions->count(), $findings),
        ];

        return ['matrix' => $matrix, 'co_coverage' => $coCoverage, 'po_evidence' => $poEvidence, 'findings' => $findings, 'summary' => $summary];
    }

    // ------------------------------------------------------- question<->CO

    /**
     * Question mapping review list: confirmed COs (question LO + confirmed rows) and AI suggestions
     * from STEP 11 with their faculty decision state.
     */
    public function questionMappings(Course $course): array
    {
        $ctx = $this->context($course);
        $rows = QuestionCoMapping::whereIn('question_id', $ctx['questions']->pluck('id'))->get()->groupBy('question_id');
        $suggestions = $this->aiSuggestions($ctx);

        return $ctx['questions']->map(function (Question $q) use ($ctx, $rows, $suggestions) {
            $decisions = ($rows->get($q->id) ?? collect())->keyBy('learning_outcome_id');
            $confirmed = collect($ctx['confirmed'][$q->id] ?? [])->filter(fn ($id) => $ctx['cos']->has($id))->map(fn ($id) => [
                'learning_outcome_id' => $id,
                'code' => $this->coCode($ctx['cos']->get($id)),
                'source' => $decisions->get($id)?->mapping_source ?? ((int) $q->learning_outcome_id === (int) $id ? 'FACULTY' : 'FACULTY'),
            ])->values()->all();

            $ai = collect($suggestions[$q->id] ?? [])->map(function ($s) use ($decisions, $ctx, $q) {
                $d = $decisions->get($s['learning_outcome_id']);
                $status = $d?->status ?? ((int) $q->learning_outcome_id === (int) $s['learning_outcome_id'] ? 'CONFIRMED' : 'PENDING');
                return [
                    'learning_outcome_id' => $s['learning_outcome_id'],
                    'code' => $this->coCode($ctx['cos']->get($s['learning_outcome_id'])),
                    'similarity_score' => $s['similarity_score'],
                    'alignment' => $s['alignment'],
                    'status' => $status,
                    'mapping_source' => $d?->mapping_source ?? 'AI_SUGGESTED',
                    'reviewed_at' => $d?->reviewed_at?->toISOString(),
                    // STEP 45: explainability targets (mapping row once faculty decided; otherwise the STEP 11 alignment row)
                    'mapping_id' => $d?->id,
                    'alignment_id' => $s['alignment_id'] ?? null,
                ];
            })->values()->all();

            return [
                'question_id' => $q->id,
                'assessment_id' => $q->assessment_id,
                'assessment_title' => $ctx['assessments']->get($q->assessment_id)?->title,
                'question_number' => $q->question_number,
                'question_text_excerpt' => mb_substr((string) $q->question_text, 0, 200),
                'marks' => (float) $q->marks,
                'cognitive_level' => $q->cognitive_level ?: $q->ai_cognitive_level,
                'faculty_learning_outcome_id' => $q->learning_outcome_id,
                'confirmed' => $confirmed,
                'ai_suggestions' => $ai,
                'is_mapped' => count($confirmed) > 0,
            ];
        })->values()->all();
    }

    /**
     * Faculty confirms or rejects a question -> CO mapping (from an AI suggestion or manually).
     *
     * @throws CoPoMappingException
     */
    public function decideQuestionMapping(Question $question, LearningOutcome $lo, User $user, bool $confirm): QuestionCoMapping
    {
        $question->loadMissing('assessment');
        if ((int) $question->assessment?->course_id !== (int) $lo->course_id) {
            throw new CoPoMappingException('The course outcome does not belong to the question\'s course.', self::STATUS_VALIDATION);
        }

        $ai = QuestionLearningOutcomeAlignment::where('question_id', $question->id)->where('learning_outcome_id', $lo->id)->orderByDesc('id')->first();

        $row = QuestionCoMapping::updateOrCreate(
            ['question_id' => $question->id, 'learning_outcome_id' => $lo->id],
            [
                'mapping_source' => QuestionCoMapping::SOURCE_FACULTY,
                'status' => $confirm ? QuestionCoMapping::STATUS_CONFIRMED : QuestionCoMapping::STATUS_REJECTED,
                'similarity_score' => $ai?->similarity_score,
                'created_by' => $user->id,
                'reviewed_at' => now(),
            ]
        );

        self::invalidateCache((int) $lo->course_id);
        $this->auditLogService->log($confirm ? 'QUESTION_CO_MAPPING_CONFIRMED' : 'QUESTION_CO_MAPPING_REJECTED', $row, $row->id, [
            'question_id' => $question->id,
            'learning_outcome_id' => $lo->id,
            'ai_similarity' => $ai?->similarity_score !== null ? (float) $ai->similarity_score : null,
        ], $user);

        return $row;
    }

    // ---------------------------------------------------------- presentation

    public function present(CoPoMappingAnalysisRun $run, bool $withFindings = true): array
    {
        $stale = $this->staleReasons($run);
        if ($stale && $run->status === CoPoMappingAnalysisRun::STATUS_COMPLETED) {
            $run->update(['status' => CoPoMappingAnalysisRun::STATUS_STALE]);
        }
        $data = [
            'id' => $run->id,
            'course_id' => $run->course_id,
            'program_id' => $run->program_id,
            'status' => $run->status,
            'is_current' => (bool) $run->is_current,
            'is_stale' => count($stale) > 0,
            'stale_reasons' => $stale,
            'mapping_version' => $run->mapping_version,
            'summary' => $run->summary,
            'matrix' => $run->matrix,
            'co_coverage' => $run->co_coverage ?? [],
            'po_evidence' => $run->po_evidence ?? [],
            'thresholds' => $run->thresholds,
            'error_message' => $run->error_message,
            'analyzed_at' => $run->analyzed_at?->toISOString(),
            'disclaimer' => self::DISCLAIMER,
        ];
        if ($withFindings) {
            $run->loadMissing('findings');
            $data['findings'] = $this->presentFindings($run);
        }
        return $data;
    }

    public function presentFindings(CoPoMappingAnalysisRun $run): array
    {
        return $run->findings->sortBy(fn (CoPoMappingFinding $f) => (CoPoMappingFinding::SEVERITY_ORDER[$f->severity] ?? 9) * 100000 + $f->id)->map(fn (CoPoMappingFinding $f) => [
            'id' => $f->id,
            'type' => $f->type,
            'severity' => $f->severity,
            'title' => $f->title,
            'description' => $f->description,
            'recommendation' => $f->recommendation,
            'category' => $f->category,
            'priority' => $f->priority,
            'course_outcome_id' => $f->course_outcome_id,
            'program_outcome_id' => $f->program_outcome_id,
            'question_id' => $f->question_id,
            'evidence' => $f->evidence ?? [],
        ])->values()->all();
    }

    /** @return string[] */
    public function staleReasons(CoPoMappingAnalysisRun $run): array
    {
        if (!$run->isCompleted() || !$run->mapping_version) {
            return [];
        }
        $course = Course::find($run->course_id);
        if (!$course) {
            return ['The course no longer exists.'];
        }
        return $this->mappingVersion($this->context($course)) === $run->mapping_version
            ? []
            : ['Course outcomes, program outcomes, mappings, questions or finalized grades changed after this analysis.'];
    }

    // ------------------------------------------------------------------ cache

    public static function cacheKey(int $courseId, string $part): string
    {
        return "course:{$courseId}:co-po:{$part}";
    }

    public static function invalidateCache(int $courseId): void
    {
        foreach (['mapping', 'matrix', 'findings', 'performance', 'evidence', 'questions'] as $part) {
            Cache::forget(self::cacheKey($courseId, $part));
        }
    }

    public static function invalidateCacheForAssessment(int $assessmentId): void
    {
        $courseId = Assessment::where('id', $assessmentId)->value('course_id');
        if ($courseId) {
            self::invalidateCache((int) $courseId);
        }
    }

    // ---------------------------------------------------------------- helpers

    /** Loads everything the calculations need in a handful of queries. */
    public function context(Course $course): array
    {
        $course->loadMissing('program');
        $cos = LearningOutcome::where('course_id', $course->id)->orderBy('sort_order')->orderBy('id')->get()->keyBy('id');
        $pos = $course->program ? ProgramOutcome::where('program_id', $course->program_id)->where('status', 'ACTIVE')->orderBy('sort_order')->orderBy('id')->get()->keyBy('id') : collect();
        $assessments = Assessment::where('course_id', $course->id)->get()->keyBy('id');
        $questions = Question::whereIn('assessment_id', $assessments->keys())->orderBy('assessment_id')->orderBy('question_number')->get();
        $mappings = CoPoMapping::where('course_id', $course->id)->get();

        $rows = QuestionCoMapping::whereIn('question_id', $questions->pluck('id'))->get();
        $rejected = [];
        $confirmedRows = [];
        foreach ($rows as $r) {
            if ($r->status === QuestionCoMapping::STATUS_CONFIRMED) {
                $confirmedRows[$r->question_id][] = (int) $r->learning_outcome_id;
            } elseif ($r->status === QuestionCoMapping::STATUS_REJECTED) {
                $rejected[$r->question_id][] = (int) $r->learning_outcome_id;
            }
        }
        $confirmed = [];
        foreach ($questions as $q) {
            $set = $confirmedRows[$q->id] ?? [];
            if ($q->learning_outcome_id && !in_array((int) $q->learning_outcome_id, $rejected[$q->id] ?? [], true)) {
                $set[] = (int) $q->learning_outcome_id;
            }
            $confirmed[$q->id] = array_values(array_unique($set));
        }

        return [
            'course' => $course,
            'program' => $course->program,
            'cos' => $cos,
            'pos' => $pos,
            'assessments' => $assessments,
            'questions' => $questions,
            'mappings' => $mappings,
            'confirmed' => $confirmed,
        ];
    }

    protected function buildMatrix(array $ctx): array
    {
        $cells = [];
        $active = 0;
        $byKey = $ctx['mappings']->keyBy(fn ($m) => $m->learning_outcome_id . ':' . $m->program_outcome_id);
        foreach ($ctx['cos'] as $lo) {
            $row = [];
            foreach ($ctx['pos'] as $po) {
                $m = $byKey->get($lo->id . ':' . $po->id);
                $level = $m ? (int) $m->mapping_level : 0;
                if ($level > 0) {
                    $active++;
                }
                $row[] = ['program_outcome_id' => $po->id, 'level' => $level, 'mapping_id' => $m?->id, 'justification' => $m?->justification];
            }
            $cells[] = ['learning_outcome_id' => $lo->id, 'code' => $this->coCode($lo), 'description' => $lo->description, 'cells' => $row];
        }
        $possible = $ctx['cos']->count() * $ctx['pos']->count();
        return [
            'program_outcomes' => $ctx['pos']->map(fn ($po) => ['id' => $po->id, 'code' => $po->code, 'title' => $po->title])->values()->all(),
            'rows' => $cells,
            'active_mappings' => $active,
            'possible_mappings' => $possible,
            'density_percent' => $possible > 0 ? round($active / $possible * 100, 2) : 0,
            'legend' => config('co_po.mapping_levels'),
        ];
    }

    /** STEP 30-style mark-weighted finalized performance per CO across all course assessments. */
    protected function coPerformance(array $ctx, array $coQuestions): array
    {
        $qToCos = [];
        foreach ($coQuestions as $loId => $qids) {
            foreach ($qids as $qid) {
                $qToCos[$qid][] = $loId;
            }
        }
        if (!$qToCos) {
            return [];
        }
        $questionMax = $ctx['questions']->keyBy('id')->map(fn ($q) => (float) $q->marks);
        $agg = [];
        foreach ($ctx['assessments'] as $assessment) {
            $rows = $this->performanceService->finalizedAnswersQuery($assessment)->get(['student_answers.question_id', 'student_answers.awarded_marks']);
            foreach ($rows as $r) {
                foreach ($qToCos[$r->question_id] ?? [] as $loId) {
                    $agg[$loId]['sum_marks'] = ($agg[$loId]['sum_marks'] ?? 0) + (float) $r->awarded_marks;
                    $agg[$loId]['sum_max'] = ($agg[$loId]['sum_max'] ?? 0) + (float) ($questionMax[$r->question_id] ?? 0);
                    $agg[$loId]['n'] = ($agg[$loId]['n'] ?? 0) + 1;
                }
            }
        }
        $out = [];
        foreach ($agg as $loId => $a) {
            $pct = $a['sum_max'] > 0 ? $a['sum_marks'] / $a['sum_max'] * 100 : null;
            $out[$loId] = [
                'sum_marks' => $a['sum_marks'],
                'sum_max' => $a['sum_max'],
                'response_count' => $a['n'],
                'average_percentage' => $pct !== null ? round($pct, 2) : null,
                'performance_gap' => $pct !== null ? round($this->performanceService->gap($pct), 2) : null,
                'performance_status' => $this->performanceService->classify($pct, $a['n']),
            ];
        }
        return $out;
    }

    protected function coStatus(float $marks, float $coverage, ?array $perf, array $t): string
    {
        if ($marks <= 0) {
            return 'NOT_ASSESSED';
        }
        if ($coverage < $t['co_min_coverage_percent']) {
            return 'LOW_COVERAGE';
        }
        $ps = $perf['performance_status'] ?? 'INSUFFICIENT_DATA';
        return match ($ps) {
            'STRONG' => 'STRONG',
            'ON_TARGET', 'MINOR_GAP' => 'ON_TARGET',
            'MODERATE_GAP', 'HIGH_GAP' => 'REVIEW',
            default => 'NO_PERFORMANCE_DATA',
        };
    }

    protected function findings(array $ctx, array $coCoverage, array $poEvidence, array $matrix, array $unmapped, array $coCognitive, array $coMarks, array $t): array
    {
        $f = [];
        $sev = fn (string $type) => (string) (config('co_po.severities')[$type] ?? 'LOW');
        $prio = fn (string $s) => match ($s) { 'HIGH' => 'high', 'MEDIUM' => 'medium', default => 'low' };
        $add = function (string $type, string $title, string $description, string $recommendation, string $category, array $extra = []) use (&$f, $sev, $prio) {
            $s = $sev($type);
            $f[] = ['type' => $type, 'severity' => $s, 'title' => $title, 'description' => $description, 'recommendation' => $recommendation, 'category' => $category, 'priority' => $prio($s)] + $extra;
        };

        if ($unmapped) {
            $labels = array_map(fn ($q) => 'Q' . $q->question_number . ' (' . ($ctx['assessments']->get($q->assessment_id)?->title ?? 'assessment') . ')', $unmapped);
            $add('UNMAPPED_QUESTION', count($unmapped) . ' question' . (count($unmapped) === 1 ? ' has' : 's have') . ' no confirmed CO mapping.',
                'Assessment marks from these questions are not attributed to any course outcome: ' . implode(', ', $labels) . '.',
                'Review each question and confirm the course outcome it provides evidence for. AI suggestions, where available, are shown for review only.',
                'learning_outcome', ['evidence' => ['question_ids' => array_map(fn ($q) => $q->id, $unmapped), 'labels' => $labels], 'question_id' => count($unmapped) === 1 ? $unmapped[0]->id : null]);
        }

        foreach ($coCoverage as $co) {
            $label = $co['display_code'];
            if ($co['mapped_marks'] <= 0) {
                $add('UNASSESSED_CO', "{$label} has no mapped assessment questions.", "{$label} (\"{$co['description']}\") is not assessed by any confirmed question across the course's assessments.",
                    "Review whether at least one assessment question should provide evidence for {$label}.", 'learning_outcome', ['course_outcome_id' => $co['learning_outcome_id'], 'evidence' => ['coverage_percent' => 0]]);
            } elseif ($co['coverage_percent'] < $t['co_min_coverage_percent']) {
                $add('LOW_CO_COVERAGE', "{$label} has low assessment coverage ({$co['coverage_percent']}%).", "Only {$co['mapped_marks']} of {$ctx['questions']->sum('marks')} assessment marks are attributed to {$label}, below the configured {$t['co_min_coverage_percent']}% threshold.",
                    "Review whether the assessment sufficiently measures {$label}, or whether additional questions should be mapped to it.", 'learning_outcome', ['course_outcome_id' => $co['learning_outcome_id'], 'evidence' => ['coverage_percent' => $co['coverage_percent'], 'mapped_marks' => $co['mapped_marks']]]);
            } elseif ($co['coverage_percent'] >= $t['co_concentration_percent']) {
                $add('CO_CONCENTRATION', "{$label} accounts for {$co['coverage_percent']}% of assessment marks.", "A single course outcome carries most of the assessment weight.",
                    'Review the distribution of assessment marks across course outcomes.', 'assessment_quality', ['course_outcome_id' => $co['learning_outcome_id'], 'evidence' => ['coverage_percent' => $co['coverage_percent']]]);
            }
            if ($co['po_mapping_count'] === 0 && $ctx['pos']->isNotEmpty()) {
                $add('MAPPING_REVIEW', "{$label} has no program outcome mapping.", "{$label} is not mapped to any PO in the CO→PO matrix.",
                    "Review whether {$label} contributes to one or more program outcomes and record the mapping level.", 'learning_outcome', ['course_outcome_id' => $co['learning_outcome_id']]);
            }
            if ($co['performance_status'] === 'MODERATE_GAP' || $co['performance_status'] === 'HIGH_GAP') {
                $add('CO_PERFORMANCE_GAP', "{$label} shows a potential performance gap ({$co['performance_percent']}%).", "Finalized student performance on questions mapped to {$label} is {$co['performance_gap']} points below the {$t['expected_performance_percent']}% benchmark ({$co['response_count']} responses).",
                    "Review the questions associated with {$label} and compare performance with instructional coverage. Performance differences do not establish causes.", 'learning_outcome', ['course_outcome_id' => $co['learning_outcome_id'], 'evidence' => ['performance_percent' => $co['performance_percent'], 'gap' => $co['performance_gap'], 'responses' => $co['response_count']]]);
            }
            // Cognitive context
            $lo = $ctx['cos']->get($co['learning_outcome_id']);
            $target = self::BLOOM_ORDER[strtolower((string) $lo?->cognitive_level)] ?? null;
            $profile = $coCognitive[$co['learning_outcome_id']] ?? [];
            $totalCo = array_sum($profile);
            if ($target && $totalCo > 0) {
                $lower = 0.0;
                foreach ($profile as $lvl => $marks) {
                    if (isset(self::BLOOM_ORDER[$lvl]) && self::BLOOM_ORDER[$lvl] < $target) {
                        $lower += $marks;
                    }
                }
                $lowerPct = round($lower / $totalCo * 100, 2);
                if ($lowerPct >= $t['cognitive_mismatch_percent']) {
                    $add('CO_COGNITIVE_MISMATCH', "Most marks for {$label} sit below its stated cognitive level ({$lo->cognitive_level}).", "{$lowerPct}% of the marks mapped to {$label} come from questions classified at lower cognitive levels.",
                        "Review whether the assessment sufficiently measures the intended cognitive level of {$label}. Cognitive classification is an AI/faculty signal, not a certainty.", 'cognitive_level', ['course_outcome_id' => $co['learning_outcome_id'], 'evidence' => ['lower_level_percent' => $lowerPct, 'profile' => $profile]]);
                }
            }
        }

        foreach ($poEvidence as $po) {
            if ($po['mapped_co_count'] === 0) {
                $add('UNMAPPED_PO', "{$po['code']} has no contribution from this course.", "No course outcome is mapped to {$po['code']} ({$po['title']}).",
                    "This may be expected — not every course contributes to every PO. Review whether a mapping is intended.", 'general', ['program_outcome_id' => $po['program_outcome_id']]);
            } elseif ($po['evidence_status'] === 'LIMITED_EVIDENCE') {
                $add('LOW_PO_EVIDENCE', "{$po['code']} has limited assessment evidence in this course ({$po['assessment_evidence_percent']}%).", "The course outcomes mapped to {$po['code']} carry little assessment weight.",
                    "Review whether the mapped course outcomes are sufficiently assessed to provide evidence for {$po['code']}.", 'general', ['program_outcome_id' => $po['program_outcome_id'], 'evidence' => ['assessment_evidence_percent' => $po['assessment_evidence_percent']]]);
            }
        }

        if ($matrix['possible_mappings'] > 0 && $matrix['density_percent'] >= $t['mapping_density_review_percent']) {
            $add('MAPPING_DENSITY', "Mapping density is {$matrix['density_percent']}%.", 'Nearly every CO is mapped to every PO. Institutional practice varies; dense matrices can make evidence less specific.',
                'Review whether each mapping reflects a meaningful contribution.', 'general', ['evidence' => ['density_percent' => $matrix['density_percent'], 'active' => $matrix['active_mappings'], 'possible' => $matrix['possible_mappings']]]);
        }

        return $f;
    }

    protected function checks(array $coCoverage, array $poEvidence, array $unmapped, int $questionCount, array $findings): array
    {
        $cosWithEvidence = count(array_filter($coCoverage, fn ($c) => $c['mapped_marks'] > 0));
        $lowCos = count(array_filter($coCoverage, fn ($c) => $c['coverage_status'] === 'LOW_COVERAGE'));
        $limitedPos = count(array_filter($poEvidence, fn ($p) => $p['evidence_status'] !== 'ASSESSED'));
        $concentration = count(array_filter($findings, fn ($f) => $f['type'] === 'CO_CONCENTRATION'));
        return [
            ['ok' => $cosWithEvidence === count($coCoverage), 'label' => $cosWithEvidence === count($coCoverage) ? 'All COs have assessment evidence' : ($cosWithEvidence . ' / ' . count($coCoverage) . ' COs have assessment evidence')],
            ['ok' => !$unmapped, 'label' => ($questionCount - count($unmapped)) . ' / ' . $questionCount . ' questions have confirmed CO mappings'],
            ['ok' => $lowCos === 0, 'label' => $lowCos === 0 ? 'No CO has low assessment coverage' : $lowCos . ' CO' . ($lowCos === 1 ? ' has' : 's have') . ' low assessment coverage'],
            ['ok' => $limitedPos === 0, 'label' => $limitedPos === 0 ? 'All POs have course evidence' : $limitedPos . ' PO' . ($limitedPos === 1 ? ' has' : 's have') . ' limited course evidence'],
            ['ok' => $concentration === 0, 'label' => $concentration === 0 ? 'No excessive mapping concentration detected' : 'Mapping concentration review suggested'],
        ];
    }

    protected function liveSummary(array $ctx): array
    {
        $matrix = $this->buildMatrix($ctx);
        $mappedQuestions = count(array_filter($ctx['confirmed'], fn ($los) => count(array_filter($los, fn ($id) => $ctx['cos']->has($id))) > 0));
        return [
            'co_count' => $ctx['cos']->count(),
            'po_count' => $ctx['pos']->count(),
            'active_mapping_count' => $matrix['active_mappings'],
            'possible_mapping_count' => $matrix['possible_mappings'],
            'mapping_density_percent' => $matrix['density_percent'],
            'question_count' => $ctx['questions']->count(),
            'questions_mapped' => $mappedQuestions,
        ];
    }

    /** AI/semantic suggestions from the current STEP 11 report of each assessment. */
    protected function aiSuggestions(array $ctx): array
    {
        $out = [];
        foreach ($ctx['assessments'] as $assessment) {
            $reportId = DB::table('analysis_reports')->where('assessment_id', $assessment->id)->where('analysis_status', 'completed')->orderByDesc('id')->value('id');
            if (!$reportId) {
                continue;
            }
            QuestionLearningOutcomeAlignment::where('analysis_report_id', $reportId)
                ->whereIn('alignment', ['STRONG_ALIGNMENT', 'WEAK_ALIGNMENT'])
                ->orderByDesc('similarity_score')
                ->get()
                ->each(function ($a) use (&$out, $ctx) {
                    if ($ctx['cos']->has($a->learning_outcome_id)) {
                        $out[$a->question_id][] = ['learning_outcome_id' => (int) $a->learning_outcome_id, 'similarity_score' => (float) $a->similarity_score, 'alignment' => $a->alignment, 'alignment_id' => $a->id];
                    }
                });
        }
        return $out;
    }

    public function mappingVersion(array $ctx): string
    {
        $parts = [];
        foreach ($ctx['cos'] as $lo) {
            $parts[] = 'co:' . $lo->id . ':' . $lo->code . ':' . $lo->cognitive_level;
        }
        foreach ($ctx['pos'] as $po) {
            $parts[] = 'po:' . $po->id . ':' . $po->code;
        }
        foreach ($ctx['mappings']->sortBy('id') as $m) {
            $parts[] = 'm:' . $m->learning_outcome_id . ':' . $m->program_outcome_id . ':' . $m->mapping_level;
        }
        foreach ($ctx['questions'] as $q) {
            $parts[] = 'q:' . $q->id . ':' . round((float) $q->marks, 2) . ':' . implode(',', $ctx['confirmed'][$q->id] ?? []) . ':' . strtolower((string) ($q->cognitive_level ?: $q->ai_cognitive_level));
        }
        foreach ($ctx['assessments'] as $assessment) {
            $parts[] = 'g:' . $assessment->id . ':' . $this->performanceService->gradingFingerprint($assessment);
        }
        return hash('sha256', implode('|', $parts));
    }

    /** Display LO codes as CO codes in the mapping UI (LO2 -> CO2) without changing stored data. */
    public function coCode(?LearningOutcome $lo): string
    {
        if (!$lo) {
            return '—';
        }
        return preg_replace('/^LO(\d+)$/i', 'CO$1', (string) $lo->code) ?: (string) $lo->code;
    }

    protected function presentCo(LearningOutcome $lo): array
    {
        return ['id' => $lo->id, 'code' => $lo->code, 'display_code' => $this->coCode($lo), 'description' => $lo->description, 'cognitive_level' => $lo->cognitive_level, 'sort_order' => $lo->sort_order];
    }

    protected function presentPo(ProgramOutcome $po): array
    {
        return ['id' => $po->id, 'program_id' => $po->program_id, 'code' => $po->code, 'title' => $po->title, 'description' => $po->description, 'sort_order' => $po->sort_order, 'status' => $po->status];
    }

    public function presentMapping(CoPoMapping $m): array
    {
        return ['id' => $m->id, 'course_id' => $m->course_id, 'learning_outcome_id' => $m->learning_outcome_id, 'program_outcome_id' => $m->program_outcome_id, 'mapping_level' => (int) $m->mapping_level, 'level_label' => CoPoMapping::levelLabel((int) $m->mapping_level), 'justification' => $m->justification, 'updated_at' => $m->updated_at?->toISOString()];
    }
}
