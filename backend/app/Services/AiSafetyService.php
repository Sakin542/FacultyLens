<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * STEP 46: AI safety checks applied to AI output before it is persisted or shown.
 *
 * Every check is deterministic. A failed check never fabricates a replacement result; the caller
 * either rejects the AI output (status FAILED / nothing saved) or flags it for faculty review.
 * Audit events carry counts, ids and flags only — never secrets, prompts, questions or answers.
 */
class AiSafetyService
{
    public const EVENT_SAFETY_CHECK_FAILED = 'AI_SAFETY_CHECK_FAILED';
    public const EVENT_HALLUCINATION_DETECTED = 'AI_HALLUCINATION_DETECTED';
    public const EVENT_PROMPT_INJECTION_BLOCKED = 'AI_PROMPT_INJECTION_BLOCKED';
    public const EVENT_PRIVACY_VIOLATION_BLOCKED = 'AI_PRIVACY_VIOLATION_BLOCKED';
    public const EVENT_CITATION_VALIDATION_FAILED = 'AI_CITATION_VALIDATION_FAILED';
    public const EVENT_GROUNDING_FAILED = 'AI_GROUNDING_FAILED';
    public const EVENT_OUTPUT_VALIDATION_FAILED = 'AI_OUTPUT_VALIDATION_FAILED';
    public const EVENT_SERVICE_FAILURE = 'AI_SERVICE_FAILURE';
    public const EVENT_RESULT_REJECTED = 'AI_RESULT_REJECTED';
    public const EVENT_CONFLICTING_EVIDENCE = 'AI_CONFLICTING_EVIDENCE_DETECTED';

    public const EVENTS = [
        self::EVENT_SAFETY_CHECK_FAILED,
        self::EVENT_HALLUCINATION_DETECTED,
        self::EVENT_PROMPT_INJECTION_BLOCKED,
        self::EVENT_PRIVACY_VIOLATION_BLOCKED,
        self::EVENT_CITATION_VALIDATION_FAILED,
        self::EVENT_GROUNDING_FAILED,
        self::EVENT_OUTPUT_VALIDATION_FAILED,
        self::EVENT_SERVICE_FAILURE,
        self::EVENT_RESULT_REJECTED,
        self::EVENT_CONFLICTING_EVIDENCE,
    ];

    public const EVIDENCE_SUFFICIENT = 'SUFFICIENT';
    public const EVIDENCE_INSUFFICIENT = 'INSUFFICIENT';
    public const EVIDENCE_CONFLICTING = 'CONFLICTING';

    /** Free-text claims FacultyLens must never surface as fact. */
    protected const UNSUPPORTED_CERTAINTY = [
        '/\bdefinitely\b/i', '/\bcertainly\b/i', '/\bundoubtedly\b/i', '/\bwithout (any )?doubt\b/i',
        '/\bexact duplicate\b/i', '/\bidentical question\b/i',
        '/\bthe student (has )?(definitely )?(failed|passed)\b/i', '/\bstudents? will fail\b/i',
        '/\bgraded incorrectly\b/i', '/\bmarked incorrectly\b/i',
        '/\bviolates? (the )?(accreditation|university|institutional|regulatory|legal)\b/i',
        '/\b(is|are) (fully )?compliant with (the )?(accreditation|university|institutional|legal|regulatory)\b/i',
        '/\b(9\d|100)\s?%\s*(accurate|accuracy|confident|confidence|certain|correct)\b/i',
        '/\b(fully|completely|totally|absolutely) (accurate|certain|confident|correct)\b/i',
    ];

    /** Output that would indicate a system prompt or secret leaked through the AI service. */
    protected const SECRET_LEAK = [
        '/<<<SYSTEM INSTRUCTIONS>>>/i', '/You are FacultyLens Academic Document Assistant/i',
        '/\bhf_[A-Za-z0-9]{12,}/', '/\bsk-[A-Za-z0-9]{16,}/',
        '/\bAI_SERVICE_API_KEY\b/', '/\bAPP_KEY\s*[:=]/', '/\bDB_PASSWORD\s*[:=]/',
        '/\bapi[_ ]?key\s*[:=]\s*\S+/i', '/\bBearer\s+[A-Za-z0-9._\-]{16,}/',
    ];

    public function __construct(protected AuditLogService $audit) {}

    /** @return string[] matched phrases (empty = clean) */
    public function unsupportedCertainty(?string $text): array
    {
        return $this->matches($text, self::UNSUPPORTED_CERTAINTY);
    }

    public function containsSecretLeak(?string $text): bool
    {
        return $this->matches($text, self::SECRET_LEAK) !== [];
    }

    /**
     * Ensure every [S#] citation points at a context block that was actually sent.
     *
     * @return array{cited:int[], invalid:int[], fabricated:bool}
     */
    public function validateCitations(?string $text, int $contextSize): array
    {
        preg_match_all('/\[S(\d+)\]/', (string) $text, $m);
        $cited = array_values(array_unique(array_map('intval', $m[1] ?? [])));
        $invalid = array_values(array_filter($cited, fn ($n) => $n < 1 || $n > $contextSize));

        return ['cited' => $cited, 'invalid' => $invalid, 'fabricated' => $cited !== [] && count($invalid) === count($cited)];
    }

    /**
     * Normalise a confidence value to [0,1]; null means "Confidence unavailable".
     */
    public function normalizeConfidence(mixed $value): ?float
    {
        if ($value === null || is_bool($value) || !is_numeric($value)) {
            return null;
        }
        $v = (float) $value;
        if (is_nan($v) || is_infinite($v)) {
            return null;
        }
        if ($v >= 5.0 && $v <= 100.0) {
            $v /= 100.0;
        }

        return ($v < 0.0 || $v > 1.0) ? null : round($v, 4);
    }

    /**
     * Assess a chat answer returned by the AI service. Returns the evidence status plus the list of
     * safety events that were recorded. Never mutates the answer.
     *
     * @param array<string,mixed> $response AI response (already shape-validated by AiService)
     * @return array{evidence_status:string, events:string[], injection_detected:bool, conflicting_evidence:array}
     */
    public function assessChatResponse(array $response, int $contextSize, Model $entity, ?int $courseId = null): array
    {
        $events = [];
        $answer = (string) ($response['answer'] ?? '');
        $safety = is_array($response['safety'] ?? null) ? $response['safety'] : [];
        $evidence = strtoupper((string) ($response['evidence_status'] ?? ''));
        if (!in_array($evidence, [self::EVIDENCE_SUFFICIENT, self::EVIDENCE_INSUFFICIENT, self::EVIDENCE_CONFLICTING], true)) {
            $evidence = ($response['grounded'] ?? false) ? self::EVIDENCE_SUFFICIENT : self::EVIDENCE_INSUFFICIENT;
        }

        $meta = ['course_id' => $courseId, 'generation_method' => Str::limit((string) ($response['generation_method'] ?? ''), 40, '')];

        if (!empty($safety['injection_detected'])) {
            $events[] = self::EVENT_PROMPT_INJECTION_BLOCKED;
            $this->audit->log(self::EVENT_PROMPT_INJECTION_BLOCKED, $entity, null, $meta + [
                'chunk_count' => count((array) ($safety['injection_chunk_ids'] ?? [])),
            ]);
        }
        if ($evidence === self::EVIDENCE_CONFLICTING) {
            $events[] = self::EVENT_CONFLICTING_EVIDENCE;
            $this->audit->log(self::EVENT_CONFLICTING_EVIDENCE, $entity, null, $meta + [
                'subjects' => count((array) ($safety['conflicting_evidence'] ?? [])),
            ]);
        }
        if ($evidence === self::EVIDENCE_INSUFFICIENT && (bool) ($response['grounded'] ?? false) === false) {
            $events[] = self::EVENT_GROUNDING_FAILED;
            $this->audit->log(self::EVENT_GROUNDING_FAILED, $entity, null, $meta + ['retrieved_count' => $contextSize]);
        }
        if (($safety['citation_validation'] ?? null) === 'failed' || $this->validateCitations($answer, $contextSize)['fabricated']) {
            $events[] = self::EVENT_CITATION_VALIDATION_FAILED;
            $this->audit->log(self::EVENT_CITATION_VALIDATION_FAILED, $entity, null, $meta);
        }
        if (($claims = $this->unsupportedCertainty($answer)) !== [] && ($response['grounded'] ?? false)) {
            $events[] = self::EVENT_HALLUCINATION_DETECTED;
            $this->audit->log(self::EVENT_HALLUCINATION_DETECTED, $entity, null, $meta + ['claim_count' => count($claims)]);
        }

        return [
            'evidence_status' => $evidence,
            'events' => $events,
            'injection_detected' => !empty($safety['injection_detected']),
            'conflicting_evidence' => array_values((array) ($safety['conflicting_evidence'] ?? [])),
        ];
    }

    /** Record that an AI result was rejected (invalid / unsafe) and nothing was saved. */
    public function recordRejection(Model|string $entity, ?int $entityId, string $reason, ?int $courseId = null): void
    {
        $this->audit->log(self::EVENT_RESULT_REJECTED, $entity, $entityId, [
            'course_id' => $courseId,
            'reason' => Str::limit($this->scrub($reason), 200, ''),
        ]);
    }

    /** Record an AI service failure (timeout / unavailable / malformed). Message is scrubbed. */
    public function recordServiceFailure(Model|string $entity, ?int $entityId, string $operation, string $error, ?int $courseId = null): void
    {
        $this->audit->log(self::EVENT_SERVICE_FAILURE, $entity, $entityId, [
            'course_id' => $courseId,
            'operation' => Str::limit($operation, 60, ''),
            'error' => Str::limit($this->scrub($error), 200, ''),
        ]);
    }

    /** Remove secrets, hosts and addresses before a message may be stored. */
    public function scrub(string $text): string
    {
        $text = preg_replace('/\bhf_[A-Za-z0-9]{6,}/i', 'hf_[redacted]', $text);
        $text = preg_replace('/(api[_ ]?key\s*[:=]\s*)\S+/i', '$1[redacted]', $text);
        $text = preg_replace('/(password\s*[:=]\s*)\S+/i', '$1[redacted]', $text);
        $text = preg_replace('/(Bearer\s+)[A-Za-z0-9._\-]{8,}/i', '$1[redacted]', $text);
        $text = preg_replace('/\b\d{1,3}(\.\d{1,3}){3}(:\d+)?\b/', '[ip]', $text);
        $text = preg_replace('/https?:\/\/[^\s]+/i', '[url]', $text);

        return (string) $text;
    }

    /** @return string[] */
    protected function matches(?string $text, array $patterns): array
    {
        if ($text === null || $text === '') {
            return [];
        }
        $found = [];
        foreach ($patterns as $p) {
            if (preg_match_all($p, $text, $m)) {
                foreach ($m[0] as $hit) {
                    $found[] = strtolower(preg_replace('/\s+/', ' ', trim($hit)));
                }
            }
        }

        return array_values(array_unique($found));
    }
}
