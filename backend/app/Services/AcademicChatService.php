<?php

namespace App\Services;

use App\Models\AcademicChatMessage;
use App\Models\AcademicChatSession;
use App\Models\AcademicChatSource;
use App\Models\Assessment;
use App\Models\Course;
use App\Models\DocumentProcessing;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * STEP 32: Session management + the grounded ask() pipeline.
 *
 * ask(): rewrite follow-up → authorized retrieval → AI service (grounded generation) →
 * persist user + assistant messages + sources (only after a valid response) → audit.
 */
class AcademicChatService
{
    public const INSUFFICIENT_EVIDENCE_TEXT = "I couldn't find enough information about that in the documents available to this chat.";
    public const DISCLAIMER = 'AI-generated answers are based only on the retrieved document excerpts and may be incomplete or imprecise. Verify against the original documents before relying on them.';

    public function __construct(
        protected AiService $aiService,
        protected AcademicDocumentRetrievalService $retrieval,
        protected AuditLogService $audit,
    ) {}

    /**
     * Create a session after validating the scope target belongs to the user.
     *
     * @param array{scope_type:string, course_id?:int|null, document_id?:int|null, assessment_id?:int|null, title?:string|null} $input
     */
    public function createSession(User $user, array $input): AcademicChatSession
    {
        $scope = strtoupper((string) ($input['scope_type'] ?? AcademicChatSession::SCOPE_COURSE));
        $attributes = ['user_id' => $user->id, 'scope_type' => $scope];
        $title = trim((string) ($input['title'] ?? ''));

        switch ($scope) {
            case AcademicChatSession::SCOPE_DOCUMENT:
                $document = DocumentProcessing::find($input['document_id'] ?? null);
                if (!$document || $document->user_id !== $user->id) {
                    throw new HttpException(403, 'Unauthorized access to document.');
                }
                $attributes['document_processing_id'] = $document->id;
                $attributes['course_id'] = $document->course_id;
                $attributes['assessment_id'] = $document->assessment_id;
                $title = $title !== '' ? $title : 'Chat: ' . Str::limit($document->original_file_name, 60, '…');
                break;

            case AcademicChatSession::SCOPE_ASSESSMENT:
                $assessment = Assessment::with('course')->find($input['assessment_id'] ?? null);
                if (!$assessment || !$assessment->course || !$this->ownsCourse($user, $assessment->course)) {
                    throw new HttpException(403, 'Unauthorized access to assessment.');
                }
                $attributes['assessment_id'] = $assessment->id;
                $attributes['course_id'] = $assessment->course_id;
                $title = $title !== '' ? $title : 'Chat: ' . Str::limit($assessment->title, 60, '…');
                break;

            case AcademicChatSession::SCOPE_COURSE:
                $course = Course::find($input['course_id'] ?? null);
                if (!$course || !$this->ownsCourse($user, $course)) {
                    throw new HttpException(403, 'Unauthorized access to course.');
                }
                $attributes['course_id'] = $course->id;
                $title = $title !== '' ? $title : 'Chat: ' . Str::limit($course->course_name ?? $course->course_code ?? 'Course', 60, '…');
                break;

            default:
                throw new InvalidArgumentException('Invalid chat scope.');
        }

        $max = (int) config('academic_chat.max_sessions_per_user', 200);
        if ($max > 0 && AcademicChatSession::where('user_id', $user->id)->count() >= $max) {
            throw new HttpException(422, 'Chat session limit reached. Please delete an older session.');
        }

        $attributes['title'] = Str::limit($title, 255, '');
        $session = AcademicChatSession::create($attributes);

        $this->audit->log('ACADEMIC_CHAT_SESSION_CREATED', $session, $session->id, [
            'scope_type' => $session->scope_type,
            'course_id' => $session->course_id,
            'document_processing_id' => $session->document_processing_id,
            'assessment_id' => $session->assessment_id,
        ], $user);

        return $session;
    }

    /**
     * Full ask pipeline. Returns the persisted assistant message (with sources loaded).
     *
     * @throws Exception when the AI service fails (nothing is persisted in that case)
     */
    public function ask(User $user, AcademicChatSession $session, string $question): AcademicChatMessage
    {
        $question = trim($question);
        $history = $this->recentHistory($session);
        $retrievalQuery = $this->rewriteFollowUp($question, $history);

        $retrieval = $this->retrieval->retrieve($user, $session, $retrievalQuery);
        $payload = [
            'question' => $question,
            'retrieval_query' => $retrievalQuery,
            'scope' => [
                'type' => $session->scope_type,
                'course_id' => $session->course_id,
                'document_id' => $session->document_processing_id,
                'assessment_id' => $session->assessment_id,
            ],
            'chunks' => $retrieval['chunks'],
            'conversation' => array_map(fn ($m) => ['role' => strtolower($m->role), 'content' => Str::limit($m->content, 2000, '')], $history),
        ];

        if ($retrieval['chunks'] === []) {
            // No evidence at all: answer deterministically without calling the generator.
            $response = [
                'answer' => self::INSUFFICIENT_EVIDENCE_TEXT,
                'sources' => [],
                'grounded' => false,
                'generation_method' => 'insufficient_evidence',
                'model' => null,
                'embedding_model' => $retrieval['embedding_model'],
                'prompt_version' => null,
                'disclaimer' => self::DISCLAIMER,
            ];
        } else {
            $response = $this->aiService->chatWithAcademicDocuments($payload);
        }

        $answer = trim((string) $response['answer']);
        if ($answer === '') {
            throw new Exception('AI Service returned an empty answer.');
        }

        // Sources come back as references to the chunks WE sent; never trust anything else.
        $chunkMap = collect($retrieval['chunks'])->keyBy('chunk_id');
        $sources = [];
        foreach ((array) $response['sources'] as $i => $src) {
            $chunk = $chunkMap->get($src['chunk_id'] ?? null);
            if (!$chunk) {
                continue;
            }
            $sources[] = [
                'document_processing_id' => $chunk['document_id'],
                'document_chunk_id' => $chunk['chunk_id'],
                'document_name' => $chunk['document_name'],
                'document_type' => $chunk['document_type'],
                'similarity_score' => $chunk['similarity_score'],
                'page_number' => $chunk['page_number'],
                'section_title' => $chunk['section_title'],
                'excerpt' => Str::limit($chunk['content'], 400, '…'),
                'source_order' => count($sources) + 1,
            ];
        }
        $grounded = (bool) $response['grounded'] && $sources !== [];

        return DB::transaction(function () use ($user, $session, $question, $answer, $response, $sources, $grounded, $retrieval, $retrievalQuery) {
            $userMessage = $session->messages()->create([
                'role' => AcademicChatMessage::ROLE_USER,
                'content' => $question,
                'status' => 'COMPLETED',
            ]);

            $assistant = $session->messages()->create([
                'role' => AcademicChatMessage::ROLE_ASSISTANT,
                'content' => $answer,
                'grounded' => $grounded,
                'generation_method' => Str::limit((string) ($response['generation_method'] ?? 'unknown'), 40, ''),
                'generation_model' => $response['model'] ?? null,
                'embedding_model' => $response['embedding_model'] ?? $retrieval['embedding_model'],
                'prompt_version' => $response['prompt_version'] ?? null,
                'retrieval_metadata' => [
                    'retrieval_query' => $retrievalQuery !== $question ? $retrievalQuery : null,
                    'candidates' => $retrieval['candidates'],
                    'retrieved_count' => count($retrieval['chunks']),
                    'used_count' => count($sources),
                    'threshold' => $retrieval['threshold'],
                    'top_k' => (int) config('academic_chat.top_k'),
                    'disclaimer' => $response['disclaimer'] ?? self::DISCLAIMER,
                ],
                'status' => 'COMPLETED',
            ]);

            foreach ($sources as $source) {
                $assistant->sources()->create($source);
            }

            $session->forceFill([
                'message_count' => $session->messages()->count(),
                'last_message_at' => now(),
            ])->save();

            $this->audit->log('ACADEMIC_CHAT_MESSAGE_SENT', $userMessage, $userMessage->id, [
                'session_id' => $session->id,
                'question_length' => mb_strlen($question),
            ], $user);
            $this->audit->log('ACADEMIC_CHAT_RESPONSE_GENERATED', $assistant, $assistant->id, [
                'session_id' => $session->id,
                'grounded' => $grounded,
                'generation_method' => $assistant->generation_method,
                'retrieved_count' => count($retrieval['chunks']),
                'used_count' => count($sources),
            ], $user);

            return $assistant->load('sources');
        });
    }

    /**
     * @return AcademicChatMessage[] oldest → newest, capped by config
     */
    public function recentHistory(AcademicChatSession $session): array
    {
        $limit = max(0, (int) config('academic_chat.max_history_messages', 10));
        if ($limit === 0) {
            return [];
        }

        return $session->messages()->reorder('id', 'desc')->limit($limit)->get()->reverse()->values()->all();
    }

    /**
     * Light-weight follow-up rewriting: when a short question leans on pronouns/ellipsis,
     * prepend the previous user question so retrieval has topical context.
     *
     * @param AcademicChatMessage[] $history
     */
    public function rewriteFollowUp(string $question, array $history): string
    {
        if ($history === []) {
            return $question;
        }

        $words = preg_split('/\s+/u', trim($question), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $isShort = count($words) <= 8;
        $hasAnaphora = (bool) preg_match('/\b(it|its|this|that|these|those|they|them|he|she|there|more|again|why|how so|elaborate|explain|example|examples)\b/i', $question);

        if (!$isShort && !$hasAnaphora) {
            return $question;
        }

        $previousUser = null;
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if ($history[$i]->role === AcademicChatMessage::ROLE_USER) {
                $previousUser = $history[$i]->content;
                break;
            }
        }
        if (!$previousUser) {
            return $question;
        }

        return Str::limit(trim($previousUser), 300, '') . ' ' . $question;
    }

    /**
     * Chat retrieval is keyed to the document owner, so scope ownership is strict (no admin bypass):
     * an admin chatting over another faculty's course would otherwise see an empty index.
     */
    protected function ownsCourse(User $user, Course $course): bool
    {
        return $course->user_id === $user->id;
    }
}
