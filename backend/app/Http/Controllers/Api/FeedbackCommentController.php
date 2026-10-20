<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\FeedbackComment;
use App\Services\CourseAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Each faculty member's personal bank of reusable grading comments (never shared with other users). */
class FeedbackCommentController extends Controller
{
    public function __construct(protected CourseAccessService $access) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'course_id' => ['nullable', 'integer'],
            'criterion' => ['nullable', 'string', 'max:255'],
            'tone' => ['nullable', Rule::in(FeedbackComment::TONES)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $comments = FeedbackComment::query()->with('course:id,course_code')
            ->where('user_id', $request->user()->id)
            // Course-specific comments for this course plus general ones
            ->when($validated['course_id'] ?? null, fn ($q, $id) => $q->where(fn ($w) => $w->where('course_id', $id)->orWhereNull('course_id')))
            ->when($validated['tone'] ?? null, fn ($q, $t) => $q->where('tone', $t))
            ->when($validated['criterion'] ?? null, fn ($q, $c) => $q->where(fn ($w) => $w->where('criterion', $c)->orWhereNull('criterion')))
            ->when($validated['search'] ?? null, function ($q, $s) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $s).'%';
                $q->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('body', 'like', $like));
            })
            ->orderByDesc('usage_count')->orderBy('title')->limit(200)->get();

        return response()->json(['status' => 'success', 'data' => $comments->map(fn ($c) => $c->toPayload())->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validated($request);
        $comment = FeedbackComment::query()->create($validated + ['user_id' => $request->user()->id]);

        return response()->json(['status' => 'success', 'message' => 'Comment saved to your bank.', 'data' => $comment->load('course:id,course_code')->toPayload()], 201);
    }

    public function update(Request $request, FeedbackComment $comment): JsonResponse
    {
        $this->owns($request, $comment);
        $comment->fill($this->validated($request, partial: true))->save();

        return response()->json(['status' => 'success', 'message' => 'Comment updated.', 'data' => $comment->load('course:id,course_code')->toPayload()]);
    }

    public function destroy(Request $request, FeedbackComment $comment): JsonResponse
    {
        $this->owns($request, $comment);
        $comment->delete();

        return response()->json(['status' => 'success', 'message' => 'Comment deleted.']);
    }

    /** Record that the comment was inserted into a student's feedback (ranks frequent comments first). */
    public function use(Request $request, FeedbackComment $comment): JsonResponse
    {
        $this->owns($request, $comment);
        $comment->increment('usage_count', 1, ['last_used_at' => now()]);

        return response()->json(['status' => 'success', 'data' => $comment->fresh('course:id,course_code')->toPayload()]);
    }

    protected function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';
        $validated = $request->validate([
            'title' => [$req, 'string', 'max:120'],
            'body' => [$req, 'string', 'max:3000'],
            'tone' => [$partial ? 'sometimes' : 'nullable', Rule::in(FeedbackComment::TONES)],
            'criterion' => ['sometimes', 'nullable', 'string', 'max:255'],
            'course_id' => ['sometimes', 'nullable', 'integer', 'exists:courses,id'],
        ]);
        if (!empty($validated['course_id'])) {
            abort_unless($this->access->can($request->user(), Course::find($validated['course_id']), 'view'), 403, 'You do not have access to that course.');
        }
        $validated['tone'] ??= $partial ? null : 'neutral';

        return array_filter($validated, fn ($v, $k) => !($partial && $k === 'tone' && $v === null), ARRAY_FILTER_USE_BOTH);
    }

    protected function owns(Request $request, FeedbackComment $comment): void
    {
        abort_unless($comment->user_id === $request->user()->id, 404);
    }
}
