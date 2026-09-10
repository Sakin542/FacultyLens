<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AcademicChatMessageRequest;
use App\Models\AcademicChatMessage;
use App\Models\AcademicChatSession;
use App\Services\AcademicChatService;
use App\Services\AcademicDocumentRetrievalService;
use App\Services\AuditLogService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * STEP 32: Document-grounded academic chat.
 */
class AcademicChatController extends Controller
{
    public function __construct(
        protected AcademicChatService $chat,
        protected AcademicDocumentRetrievalService $retrieval,
        protected AuditLogService $audit,
    ) {}

    /**
     * GET /api/academic-chat/sessions?course_id=&document_id=
     */
    public function index(Request $request): JsonResponse
    {
        $query = AcademicChatSession::where('user_id', $request->user()->id)
            ->with(['course:id,course_name,course_code', 'document:id,original_file_name,document_type', 'assessment:id,title'])
            ->orderByDesc('updated_at');

        if ($request->filled('course_id')) {
            $query->where('course_id', (int) $request->input('course_id'));
        }
        if ($request->filled('document_id')) {
            $query->where('document_processing_id', (int) $request->input('document_id'));
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Chat sessions retrieved.',
            'data' => $query->limit(100)->get()->map(fn ($s) => $this->sessionPayload($s))->values(),
        ]);
    }

    /**
     * POST /api/academic-chat/sessions
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'scope_type' => ['required', Rule::in(AcademicChatSession::SCOPES)],
            'course_id' => ['required_if:scope_type,COURSE', 'nullable', 'integer'],
            'document_id' => ['required_if:scope_type,DOCUMENT', 'nullable', 'integer'],
            'assessment_id' => ['required_if:scope_type,ASSESSMENT', 'nullable', 'integer'],
            'title' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $session = $this->chat->createSession($request->user(), $validated);
        } catch (HttpException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $e->getStatusCode());
        }

        $session->load(['course:id,course_name,course_code', 'document:id,original_file_name,document_type', 'assessment:id,title']);

        return response()->json([
            'status' => 'success',
            'message' => 'Chat session created.',
            'data' => $this->sessionPayload($session, true, $request),
        ], 201);
    }

    /**
     * GET /api/academic-chat/sessions/{session}
     */
    public function show(Request $request, AcademicChatSession $session): JsonResponse
    {
        if ($session->user_id !== $request->user()->id) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized access to chat session.'], 403);
        }

        $session->load([
            'course:id,course_name,course_code',
            'document:id,original_file_name,document_type',
            'assessment:id,title',
            'messages.sources',
        ]);

        $this->audit->log('ACADEMIC_CHAT_VIEWED', $session, $session->id, ['message_count' => $session->messages->count()], $request->user());

        return response()->json([
            'status' => 'success',
            'message' => 'Chat session retrieved.',
            'data' => $this->sessionPayload($session, true, $request) + [
                'messages' => $session->messages->map(fn ($m) => $this->messagePayload($m))->values(),
            ],
        ]);
    }

    /**
     * DELETE /api/academic-chat/sessions/{session}
     */
    public function destroy(Request $request, AcademicChatSession $session): JsonResponse
    {
        if ($session->user_id !== $request->user()->id) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized access to chat session.'], 403);
        }

        $session->delete();

        return response()->json(['status' => 'success', 'message' => 'Chat session deleted.', 'data' => null]);
    }

    /**
     * POST /api/academic-chat/sessions/{session}/messages
     */
    public function sendMessage(AcademicChatMessageRequest $request, AcademicChatSession $session): JsonResponse
    {
        if ($session->user_id !== $request->user()->id) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized access to chat session.'], 403);
        }

        $summary = $this->retrieval->scopeIndexSummary($request->user(), $session);
        if ($summary['indexed'] === 0) {
            $message = $summary['total'] === 0
                ? 'No documents are available in this chat scope. Upload documents to the course first.'
                : ($summary['indexing'] > 0
                    ? 'Documents in this scope are still being indexed. Please try again shortly.'
                    : 'No indexed documents are available in this chat scope.');

            return response()->json(['status' => 'error', 'message' => $message, 'data' => ['index' => $summary]], 409);
        }

        try {
            $assistant = $this->chat->ask($request->user(), $session, $request->validated('message'));
        } catch (Exception $e) {
            Log::warning("AcademicChatController: ask failed for session {$session->id}: {$e->getMessage()}");

            return response()->json([
                'status' => 'error',
                'message' => 'The assistant could not answer right now. Your question was not saved — please try again.',
            ], 503);
        }

        $userMessage = $session->messages()->where('role', AcademicChatMessage::ROLE_USER)->reorder('id', 'desc')->first();

        return response()->json([
            'status' => 'success',
            'message' => 'Response generated.',
            'data' => [
                'user_message' => $userMessage ? $this->messagePayload($userMessage) : null,
                'assistant_message' => $this->messagePayload($assistant),
                'session' => $this->sessionPayload($session->fresh()),
            ],
        ], 201);
    }

    protected function sessionPayload(AcademicChatSession $session, bool $withIndex = false, ?Request $request = null): array
    {
        $payload = [
            'id' => $session->id,
            'title' => $session->title,
            'scope_type' => $session->scope_type,
            'course_id' => $session->course_id,
            'document_id' => $session->document_processing_id,
            'assessment_id' => $session->assessment_id,
            'course' => $session->relationLoaded('course') && $session->course ? [
                'id' => $session->course->id,
                'course_name' => $session->course->course_name,
                'course_code' => $session->course->course_code,
            ] : null,
            'document' => $session->relationLoaded('document') && $session->document ? [
                'id' => $session->document->id,
                'name' => $session->document->original_file_name,
                'document_type' => $session->document->document_type,
            ] : null,
            'assessment' => $session->relationLoaded('assessment') && $session->assessment ? [
                'id' => $session->assessment->id,
                'title' => $session->assessment->title,
            ] : null,
            'status' => $session->status,
            'message_count' => $session->message_count,
            'last_message_at' => $session->last_message_at?->toIso8601String(),
            'created_at' => $session->created_at?->toIso8601String(),
            'updated_at' => $session->updated_at?->toIso8601String(),
        ];

        if ($withIndex && $request) {
            $payload['index'] = $this->retrieval->scopeIndexSummary($request->user(), $session);
        }

        return $payload;
    }

    protected function messagePayload(AcademicChatMessage $message): array
    {
        $meta = $message->retrieval_metadata ?? [];

        return [
            'id' => $message->id,
            'role' => $message->role,
            'content' => $message->content,
            'grounded' => (bool) $message->grounded,
            'generation_method' => $message->generation_method,
            'generation_model' => $message->generation_model,
            'embedding_model' => $message->embedding_model,
            'prompt_version' => $message->prompt_version,
            'retrieved_count' => $meta['retrieved_count'] ?? null,
            'used_count' => $meta['used_count'] ?? null,
            'disclaimer' => $message->role === AcademicChatMessage::ROLE_ASSISTANT
                ? ($meta['disclaimer'] ?? AcademicChatService::DISCLAIMER)
                : null,
            'sources' => $message->relationLoaded('sources')
                ? $message->sources->map(fn ($s) => [
                    'id' => $s->id,
                    'document_id' => $s->document_processing_id,
                    'chunk_id' => $s->document_chunk_id,
                    'document_name' => $s->document_name,
                    'document_type' => $s->document_type,
                    'similarity_score' => $s->similarity_score,
                    'page_number' => $s->page_number,
                    'section_title' => $s->section_title,
                    'excerpt' => $s->excerpt,
                    'source_order' => $s->source_order,
                ])->values()
                : [],
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }
}
