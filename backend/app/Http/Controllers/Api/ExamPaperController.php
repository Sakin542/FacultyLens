<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\AssessmentBlueprint;
use App\Models\ExamPaper;
use App\Models\ExamPaperItem;
use App\Services\AiService;
use App\Services\AuditLogService;
use App\Services\CourseAccessService;
use App\Services\Exam\ExamPaperBuilder;
use App\Services\Exam\ExamPaperExporter;
use App\Services\Exam\ExamSetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ExamPaperController extends Controller
{
    public function __construct(
        protected ExamPaperBuilder $builder,
        protected ExamSetService $sets,
        protected ExamPaperExporter $exporter,
        protected CourseAccessService $access,
        protected AuditLogService $audit,
    ) {}

    // ------------------------------------------------------------------ assessment-level

    public function index(Request $request, Assessment $assessment): JsonResponse
    {
        $this->authorizeAssessment($request, $assessment, 'view');
        $papers = ExamPaper::query()->where('assessment_id', $assessment->id)->whereNull('parent_paper_id')
            ->with(['sets', 'creator:id,name'])->withCount('items')->latest()->get();

        return response()->json(['status' => 'success', 'data' => $papers->map(fn (ExamPaper $p) => [
            'id' => $p->id,
            'title' => $p->title,
            'status' => $p->status,
            'build_mode' => $p->build_mode,
            'total_marks' => $p->total_marks,
            'items_count' => $p->items_count,
            'shortfalls' => $p->shortfalls ?? [],
            'sets' => $p->sets->map(fn ($s) => ['id' => $s->id, 'set_label' => $s->set_label, 'status' => $s->status])->values(),
            'creator' => $p->creator?->only(['id', 'name']),
            'applied_at' => $p->applied_at?->toIso8601String(),
            'created_at' => $p->created_at?->toIso8601String(),
        ])]);
    }

    public function pool(Request $request, Assessment $assessment): JsonResponse
    {
        $this->authorizeAssessment($request, $assessment, 'view');
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'cognitive_level' => ['nullable', 'string', 'max:16'],
            'difficulty_level' => ['nullable', 'string', 'max:16'],
            'source' => ['nullable', Rule::in(['bank', 'question', 'generated'])],
        ]);
        $pool = $this->builder->candidates($assessment, $filters);

        return response()->json([
            'status' => 'success',
            'data' => $pool->take(200)->values(),
            'meta' => [
                'total' => $pool->count(),
                'by_source' => $pool->countBy('source_type'),
                'by_bloom' => $pool->countBy(fn ($c) => $c['cognitive_level'] ?? 'Unclassified'),
            ],
        ]);
    }

    public function store(Request $request, Assessment $assessment): JsonResponse
    {
        $this->authorizeAssessment($request, $assessment, 'edit_question');
        $validated = $request->validate([
            'mode' => ['required', Rule::in(['blueprint', 'targets', 'empty'])],
            'blueprint_id' => ['nullable', 'integer'],
            'title' => ['nullable', 'string', 'max:255'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:600'],
            'seed' => ['nullable', 'integer', 'min:1', 'max:999999'],
            'targets' => ['required_if:mode,targets', 'array'],
            'targets.question_count' => ['required_if:mode,targets', 'integer', 'min:1', 'max:80'],
            'targets.total_marks' => ['nullable', 'numeric', 'min:1', 'max:1000'],
            'targets.marks_each' => ['nullable', 'numeric', 'min:0.5', 'max:100'],
            'targets.cognitive_mix' => ['nullable', 'array'],
            'targets.cognitive_mix.*' => ['numeric', 'min:0', 'max:100'],
            'targets.difficulty_mix' => ['nullable', 'array'],
            'targets.difficulty_mix.*' => ['numeric', 'min:0', 'max:100'],
            'targets.topic' => ['nullable', 'string', 'max:255'],
            'targets.question_type' => ['nullable', 'string', 'max:32'],
        ]);

        $attributes = [
            'title' => $validated['title'] ?? $assessment->title,
            'instructions' => $validated['instructions'] ?? null,
            'duration_minutes' => $validated['duration_minutes'] ?? null,
            'seed' => $validated['seed'] ?? null,
            'build_mode' => $validated['mode'] === 'empty' ? 'manual' : $validated['mode'],
        ];

        if ($validated['mode'] === 'empty') {
            $paper = ExamPaper::query()->create([
                'assessment_id' => $assessment->id,
                'created_by' => $request->user()->id,
                'title' => $attributes['title'],
                'instructions' => $attributes['instructions'] ?? 'Answer all questions. Figures in the right margin indicate full marks.',
                'duration_minutes' => $attributes['duration_minutes'] ?? $assessment->duration_minutes,
                'build_mode' => 'manual',
                'seed' => $attributes['seed'] ?? random_int(1, 999999),
            ]);
            $paper = $this->builder->refreshTotals($paper);
        } elseif ($validated['mode'] === 'blueprint') {
            $blueprint = AssessmentBlueprint::query()->where('assessment_id', $assessment->id)
                ->when($validated['blueprint_id'] ?? null, fn ($q, $id) => $q->whereKey($id), fn ($q) => $q->where('is_current', true))
                ->first();
            if (!$blueprint) {
                return response()->json(['status' => 'error', 'message' => 'This assessment has no blueprint yet. Create one, or build from custom targets.'], 422);
            }
            $attributes['blueprint_id'] = $blueprint->id;
            $attributes['duration_minutes'] ??= $blueprint->duration_minutes;
            $attributes['instructions'] ??= $blueprint->instructions;
            $paper = $this->builder->build($assessment, $this->builder->slotsFromBlueprint($blueprint), $attributes, $request->user());
        } else {
            $attributes['targets'] = $validated['targets'];
            $paper = $this->builder->build($assessment, $this->builder->slotsFromTargets($validated['targets']), $attributes, $request->user());
        }

        $this->audit->log('EXAM_PAPER_CREATED', $paper, $paper->id, ['mode' => $validated['mode'], 'items' => $paper->items->count()], $request->user());

        return response()->json(['status' => 'success', 'message' => $this->buildMessage($paper), 'data' => $this->payload($paper)], 201);
    }

    // ------------------------------------------------------------------ paper-level

    public function show(Request $request, ExamPaper $paper): JsonResponse
    {
        $this->authorizePaper($request, $paper, 'view');

        return response()->json(['status' => 'success', 'data' => $this->payload($paper)]);
    }

    public function update(Request $request, ExamPaper $paper): JsonResponse
    {
        $this->authorizePaper($request, $paper, 'edit_question');
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'instructions' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:5', 'max:600'],
            'status' => ['sometimes', Rule::in(['draft', 'final'])],
        ]);
        if ($paper->isFinal() && ($validated['status'] ?? 'final') === 'final' && array_diff_key($validated, ['status' => 1]) !== []) {
            return $this->locked();
        }
        if (($validated['status'] ?? null) === 'final' && !$paper->isFinal()) {
            if ($paper->items()->count() === 0) {
                return response()->json(['status' => 'error', 'message' => 'Add questions before finalizing the paper.'], 422);
            }
            $validated['finalized_at'] = now();
        } elseif (($validated['status'] ?? null) === 'draft') {
            $validated['finalized_at'] = null;
        }
        $paper->fill($validated)->save();

        return response()->json(['status' => 'success', 'message' => 'Paper updated.', 'data' => $this->payload($paper->fresh())]);
    }

    public function destroy(Request $request, ExamPaper $paper): JsonResponse
    {
        $this->authorizePaper($request, $paper, 'edit_question');
        $paper->delete();

        return response()->json(['status' => 'success', 'message' => 'Paper deleted.']);
    }

    public function addItem(Request $request, ExamPaper $paper): JsonResponse
    {
        $this->authorizePaper($request, $paper, 'edit_question');
        if ($paper->isFinal()) {
            return $this->locked();
        }
        $validated = $request->validate([
            'candidate_key' => ['nullable', 'string', 'max:40', 'required_without:question_text'],
            'question_text' => ['nullable', 'string', 'max:5000'],
            'expected_answer' => ['nullable', 'string', 'max:10000'],
            'marks' => ['nullable', 'numeric', 'min:0.5', 'max:100'],
            'cognitive_level' => ['nullable', 'string', 'max:16'],
            'difficulty_level' => ['nullable', 'string', 'max:16'],
            'section_title' => ['nullable', 'string', 'max:255'],
        ]);

        if (!empty($validated['candidate_key'])) {
            $this->builder->addCandidate($paper, $validated['candidate_key'], isset($validated['marks']) ? (float) $validated['marks'] : null);
        } else {
            $paper->items()->create([
                'sort_order' => (int) $paper->items()->max('sort_order') + 1,
                'section_title' => $validated['section_title'] ?? null,
                'source_type' => 'manual',
                'question_text' => $validated['question_text'],
                'expected_answer' => $validated['expected_answer'] ?? null,
                'marks' => $validated['marks'] ?? 1,
                'cognitive_level' => ExamPaperBuilder::bloom($validated['cognitive_level'] ?? null),
                'difficulty_level' => ExamPaperBuilder::difficulty($validated['difficulty_level'] ?? null),
            ]);
            $this->builder->refreshTotals($paper);
        }

        return response()->json(['status' => 'success', 'message' => 'Question added.', 'data' => $this->payload($paper->fresh())], 201);
    }

    public function updateItem(Request $request, ExamPaper $paper, ExamPaperItem $item): JsonResponse
    {
        $this->authorizeItem($request, $paper, $item);
        if ($paper->isFinal()) {
            return $this->locked();
        }
        $validated = $request->validate([
            'question_text' => ['sometimes', 'string', 'max:5000'],
            'expected_answer' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'marks' => ['sometimes', 'numeric', 'min:0.5', 'max:100'],
            'section_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'cognitive_level' => ['sometimes', 'nullable', 'string', 'max:16'],
            'difficulty_level' => ['sometimes', 'nullable', 'string', 'max:16'],
            'answer_needs_review' => ['sometimes', 'boolean'],
            'variant_meta' => ['sometimes', 'nullable', 'array'],
        ]);
        foreach (['cognitive_level' => 'bloom', 'difficulty_level' => 'difficulty'] as $field => $fn) {
            if (array_key_exists($field, $validated)) {
                $validated[$field] = ExamPaperBuilder::$fn($validated[$field]);
            }
        }
        $item->fill($validated)->save();
        $this->builder->refreshTotals($paper);

        return response()->json(['status' => 'success', 'message' => 'Question updated.', 'data' => $this->payload($paper->fresh())]);
    }

    public function destroyItem(Request $request, ExamPaper $paper, ExamPaperItem $item): JsonResponse
    {
        $this->authorizeItem($request, $paper, $item);
        if ($paper->isFinal()) {
            return $this->locked();
        }
        $item->delete();
        $this->renumber($paper);
        $this->builder->refreshTotals($paper);

        return response()->json(['status' => 'success', 'message' => 'Question removed.', 'data' => $this->payload($paper->fresh())]);
    }

    public function reorder(Request $request, ExamPaper $paper): JsonResponse
    {
        $this->authorizePaper($request, $paper, 'edit_question');
        if ($paper->isFinal()) {
            return $this->locked();
        }
        $validated = $request->validate(['item_ids' => ['required', 'array', 'min:1'], 'item_ids.*' => ['integer']]);
        $ids = $paper->items()->pluck('id')->all();
        if (count($validated['item_ids']) !== count($ids) || array_diff($ids, $validated['item_ids']) !== []) {
            return response()->json(['status' => 'error', 'message' => 'The new order must list every question in the paper exactly once.'], 422);
        }
        foreach ($validated['item_ids'] as $i => $id) {
            ExamPaperItem::query()->whereKey($id)->update(['sort_order' => $i + 1]);
        }

        return response()->json(['status' => 'success', 'message' => 'Order saved.', 'data' => $this->payload($paper->fresh())]);
    }

    /** Preview AI variants of one question (nothing is saved until the faculty member picks one). */
    public function variants(Request $request, ExamPaper $paper, ExamPaperItem $item, AiService $ai): JsonResponse
    {
        $this->authorizeItem($request, $paper, $item);
        $validated = $request->validate([
            'count' => ['nullable', 'integer', 'min:1', 'max:5'],
            'mode' => ['nullable', Rule::in(['numbers', 'wording', 'both'])],
        ]);
        try {
            $result = $ai->generateQuestionVariants([
                'question_text' => $item->question_text,
                'expected_answer' => $item->expected_answer,
                'count' => (int) ($validated['count'] ?? 3),
                'mode' => $validated['mode'] ?? 'both',
                'seed' => random_int(1, 999999),
            ]);
        } catch (Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 503);
        }

        return response()->json(['status' => 'success', 'data' => $result]);
    }

    public function createSets(Request $request, ExamPaper $paper): JsonResponse
    {
        $this->authorizePaper($request, $paper, 'edit_question');
        $validated = $request->validate([
            'count' => ['required', 'integer', 'min:1', 'max:4'],
            'vary' => ['required', Rule::in(['shuffle', 'numbers', 'wording', 'both'])],
        ]);
        $result = $this->sets->createSets($paper, (int) $validated['count'], $validated['vary'], $request->user());
        $this->audit->log('EXAM_SETS_CREATED', $paper, $paper->id, ['count' => count($result['sets']), 'vary' => $validated['vary']], $request->user());

        return response()->json([
            'status' => 'success',
            'message' => count($result['sets']).' set(s) created.',
            'data' => array_map(fn ($s) => $this->payload($s), $result['sets']),
            'warnings' => $result['warnings'],
        ], 201);
    }

    public function apply(Request $request, ExamPaper $paper): JsonResponse
    {
        $this->authorizePaper($request, $paper, 'edit_question');
        if ($paper->applied_at) {
            return response()->json(['status' => 'error', 'message' => 'This paper was already added to the assessment.'], 409);
        }
        $count = $this->sets->applyToAssessment($paper);
        $this->audit->log('EXAM_PAPER_APPLIED', $paper, $paper->id, ['questions' => $count], $request->user());

        return response()->json(['status' => 'success', 'message' => "{$count} question(s) added to the assessment.", 'data' => $this->payload($paper->fresh())]);
    }

    public function export(Request $request, ExamPaper $paper): Response
    {
        $this->authorizePaper($request, $paper, 'view');
        $validated = $request->validate([
            'format' => ['nullable', Rule::in(['pdf', 'docx'])],
            'answer_key' => ['nullable', 'boolean'],
        ]);
        $format = $validated['format'] ?? 'pdf';
        $withKey = filter_var($validated['answer_key'] ?? false, FILTER_VALIDATE_BOOL);
        // The answer key is examiner material: same bar as editing questions
        if ($withKey && !$this->access->can($request->user(), $paper->assessment->course, 'edit_question')) {
            abort(403, 'Only course editors can export the answer key.');
        }

        if ($format === 'docx' && !class_exists(\ZipArchive::class)) {
            return response()->json(['status' => 'error', 'message' => 'Word export needs the PHP zip extension on the server. Use PDF, or enable ext-zip.'], 503);
        }
        $bytes = $format === 'docx' ? $this->exporter->docx($paper, $withKey) : $this->exporter->pdf($paper, $withKey);
        $this->audit->log('EXAM_PAPER_EXPORTED', $paper, $paper->id, ['format' => $format, 'answer_key' => $withKey], $request->user());

        return response($bytes, 200, [
            'Content-Type' => $format === 'docx' ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' : 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$this->exporter->filename($paper, $format, $withKey).'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    // ------------------------------------------------------------------ helpers

    protected function payload(ExamPaper $paper): array
    {
        $paper->loadMissing(['items.learningOutcome', 'assessment.course', 'sets', 'parent']);

        return [
            'id' => $paper->id,
            'assessment' => ['id' => $paper->assessment->id, 'title' => $paper->assessment->title],
            'course' => ['id' => $paper->assessment->course->id, 'course_code' => $paper->assessment->course->course_code, 'course_name' => $paper->assessment->course->course_name],
            'title' => $paper->title,
            'set_label' => $paper->set_label,
            'parent_paper_id' => $paper->parent_paper_id,
            'instructions' => $paper->instructions,
            'duration_minutes' => $paper->duration_minutes,
            'total_marks' => $paper->total_marks,
            'status' => $paper->status,
            'build_mode' => $paper->build_mode,
            'coverage' => $paper->coverage,
            'shortfalls' => $paper->shortfalls ?? [],
            'items' => $paper->items->map(fn (ExamPaperItem $i) => $i->toPayload())->values(),
            'sets' => $paper->parent_paper_id ? [] : $paper->sets->map(fn ($s) => ['id' => $s->id, 'set_label' => $s->set_label, 'status' => $s->status])->values(),
            'finalized_at' => $paper->finalized_at?->toIso8601String(),
            'applied_at' => $paper->applied_at?->toIso8601String(),
        ];
    }

    protected function buildMessage(ExamPaper $paper): string
    {
        $missing = collect($paper->shortfalls ?? [])->sum('missing');

        return $missing > 0
            ? "Paper drafted with {$paper->items->count()} question(s). {$missing} slot(s) could not be filled from the question pool; add bank questions or generate drafts for them."
            : "Paper drafted with {$paper->items->count()} question(s) matching the targets.";
    }

    protected function renumber(ExamPaper $paper): void
    {
        foreach ($paper->items()->get() as $i => $item) {
            if ($item->sort_order !== $i + 1) {
                $item->forceFill(['sort_order' => $i + 1])->save();
            }
        }
    }

    protected function locked(): JsonResponse
    {
        return response()->json(['status' => 'error', 'message' => 'This paper is final. Move it back to draft to edit it.'], 409);
    }

    protected function authorizeAssessment(Request $request, Assessment $assessment, string $ability): void
    {
        $assessment->loadMissing('course');
        abort_unless($this->access->can($request->user(), $assessment->course, $ability), 403, 'Unauthorized access to this assessment.');
    }

    protected function authorizePaper(Request $request, ExamPaper $paper, string $ability): void
    {
        $paper->loadMissing('assessment.course');
        abort_unless($this->access->can($request->user(), $paper->assessment->course, $ability), 403, 'Unauthorized access to this exam paper.');
    }

    protected function authorizeItem(Request $request, ExamPaper $paper, ExamPaperItem $item): void
    {
        abort_unless($item->exam_paper_id === $paper->id, 404);
        $this->authorizePaper($request, $paper, 'edit_question');
    }
}
