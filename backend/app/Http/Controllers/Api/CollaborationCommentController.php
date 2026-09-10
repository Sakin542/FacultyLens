<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CollaborationComment;
use App\Models\Course;
use App\Services\CollaborationCommentService;
use App\Services\CourseAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * STEP 34: discussion threads on academic resources. Comments never change AI results or official content.
 */
class CollaborationCommentController extends Controller
{
    public function __construct(protected CollaborationCommentService $service, protected CourseAccessService $access) {}

    /** GET /api/courses/{course}/comments?commentable_type=&commentable_id=&status=&page= */
    public function index(Request $request, Course $course): JsonResponse
    {
        if (!$request->user()->can('view', $course)) {
            return $this->forbidden('You do not have access to this course.');
        }
        $filters = $request->validate([
            'commentable_type' => ['nullable', Rule::in(array_keys(config('collaboration.commentables')))],
            'commentable_id' => ['nullable', 'integer', 'required_with:commentable_type'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'RESOLVED', 'active', 'resolved'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        try {
            $page = $this->service->list($request->user(), $course, $filters, (int) ($filters['per_page'] ?? 20));
        } catch (HttpException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $e->getStatusCode());
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Comments retrieved.',
            'data' => collect($page->items())->map(fn ($c) => $this->service->payload($c))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
                'can_comment' => $this->access->can($request->user(), $course, 'comment')],
        ]);
    }

    /** POST /api/courses/{course}/comments */
    public function store(Request $request, Course $course): JsonResponse
    {
        if (!$request->user()->can('comment', $course)) {
            return $this->forbidden('You do not have permission to comment on this course.');
        }
        $validated = $request->validate([
            'commentable_type' => ['required', Rule::in(array_keys(config('collaboration.commentables')))],
            'commentable_id' => ['required', 'integer'],
            'parent_id' => ['nullable', 'integer'],
            'body' => ['required', 'string', 'min:1', 'max:' . config('collaboration.max_comment_length', 5000)],
            'mentions' => ['nullable', 'array', 'max:20'],
            'mentions.*' => ['integer'],
        ]);

        try {
            $comment = $this->service->create($request->user(), $course, $validated);
        } catch (HttpException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $e->getStatusCode());
        }

        return response()->json(['status' => 'success', 'message' => 'Comment added.', 'data' => $this->service->payload($comment)], 201);
    }

    /** GET /api/comments/{comment} */
    public function show(Request $request, CollaborationComment $comment): JsonResponse
    {
        if (!$request->user()->can('view', $comment->course)) {
            return $this->forbidden('You do not have access to this discussion.');
        }
        $comment->load(['user:id,name', 'resolver:id,name', 'replies.user:id,name']);

        return response()->json(['status' => 'success', 'message' => 'Comment retrieved.', 'data' => $this->service->payload($comment)]);
    }

    /** PUT /api/comments/{comment} */
    public function update(Request $request, CollaborationComment $comment): JsonResponse
    {
        if (!$request->user()->can('view', $comment->course)) {
            return $this->forbidden('You do not have access to this discussion.');
        }
        $validated = $request->validate(['body' => ['required', 'string', 'min:1', 'max:' . config('collaboration.max_comment_length', 5000)]]);

        return $this->attempt(fn () => ['Comment updated.', $this->service->payload($this->service->update($request->user(), $comment, $validated['body']))]);
    }

    /** DELETE /api/comments/{comment} */
    public function destroy(Request $request, CollaborationComment $comment): JsonResponse
    {
        if (!$request->user()->can('view', $comment->course)) {
            return $this->forbidden('You do not have access to this discussion.');
        }

        return $this->attempt(function () use ($request, $comment) {
            $this->service->delete($request->user(), $comment);

            return ['Comment deleted.', null];
        });
    }

    /** POST /api/comments/{comment}/resolve */
    public function resolve(Request $request, CollaborationComment $comment): JsonResponse
    {
        if (!$request->user()->can('view', $comment->course)) {
            return $this->forbidden('You do not have access to this discussion.');
        }

        return $this->attempt(fn () => ['Discussion resolved.', $this->service->payload($this->service->resolve($request->user(), $comment))]);
    }

    /** POST /api/comments/{comment}/reopen */
    public function reopen(Request $request, CollaborationComment $comment): JsonResponse
    {
        if (!$request->user()->can('view', $comment->course)) {
            return $this->forbidden('You do not have access to this discussion.');
        }

        return $this->attempt(fn () => ['Discussion reopened.', $this->service->payload($this->service->reopen($request->user(), $comment))]);
    }

    protected function attempt(callable $fn): JsonResponse
    {
        try {
            [$message, $data] = $fn();
        } catch (HttpException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $e->getStatusCode());
        }

        return response()->json(['status' => 'success', 'message' => $message, 'data' => $data]);
    }

    protected function forbidden(string $message): JsonResponse
    {
        return response()->json(['status' => 'error', 'message' => $message], 403);
    }
}
