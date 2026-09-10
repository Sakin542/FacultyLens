<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Request;

class AuditLogService
{
    /**
     * Keys that must never be stored in audit log metadata to protect academic confidentiality and secrets.
     */
    protected const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'token',
        'api_key',
        'hf_token',
        'secret',
        'extracted_text',
        'raw_text',
        'cleaned_text',
        'question_text',
        'questions_raw',
        'syllabus_text',
        'answer_text',
        'original_answer_text',
        'faculty_feedback',
        'email',
    ];

    /**
     * Record an immutable audit log entry for a critical academic or security event.
     */
    public function log(
        string $action,
        Model|string $entityType,
        ?int $entityId = null,
        ?array $metadata = null,
        ?User $user = null
    ): AuditLog {
        $resolvedEntityType = is_object($entityType) ? class_basename($entityType) : $entityType;
        $resolvedEntityId = $entityId ?? ($entityType instanceof Model ? $entityType->getKey() : null);
        $resolvedUser = $user ?? request()?->user();

        $sanitizedMetadata = $metadata ? $this->sanitizeMetadata($metadata) : null;

        return AuditLog::create([
            'user_id' => $resolvedUser?->id,
            'action' => strtoupper($action),
            'entity_type' => $resolvedEntityType,
            'entity_id' => $resolvedEntityId,
            'course_id' => $this->resolveCourseId($entityType, $metadata),
            'metadata' => $sanitizedMetadata,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent() ? substr(request()->userAgent(), 0, 255) : null,
            'created_at' => now(),
        ]);
    }

    /**
     * STEP 34: best-effort course linkage so the per-course activity feed can filter audit rows.
     */
    protected function resolveCourseId(Model|string $entity, ?array $metadata): ?int
    {
        if (is_array($metadata) && is_numeric($metadata['course_id'] ?? null)) {
            return (int) $metadata['course_id'];
        }
        if (!$entity instanceof Model) {
            return null;
        }
        try {
            if ($entity instanceof \App\Models\Course) {
                return (int) $entity->getKey();
            }
            if (isset($entity->course_id) && is_numeric($entity->course_id)) {
                return (int) $entity->course_id;
            }
            if ($entity instanceof \App\Models\Question || $entity instanceof \App\Models\Rubric || $entity instanceof \App\Models\AnalysisReport) {
                return $entity->assessment?->course_id ? (int) $entity->assessment->course_id : null;
            }
            if ($entity instanceof \App\Models\Recommendation) {
                return $entity->analysisReport?->assessment?->course_id ? (int) $entity->analysisReport->assessment->course_id : null;
            }
            if ($entity instanceof \App\Models\GeneratedQuestion) {
                return $entity->request?->course_id ? (int) $entity->request->course_id : null;
            }
            if ($entity instanceof \App\Models\AcademicChatMessage) {
                return $entity->session?->course_id ? (int) $entity->session->course_id : null;
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    /**
     * Deeply sanitize metadata array to prevent accidental leakage of sensitive academic content or secrets.
     */
    protected function sanitizeMetadata(array $data): array
    {
        $clean = [];

        foreach ($data as $key => $value) {
            $lowerKey = strtolower((string) $key);

            if (in_array($lowerKey, self::SENSITIVE_KEYS, true)) {
                $clean[$key] = '[REDACTED]';
                continue;
            }

            if (is_array($value)) {
                $clean[$key] = $this->sanitizeMetadata($value);
            } elseif (is_string($value) && strlen($value) > 250) {
                // Truncate excessively long strings in audit log to keep metadata lightweight
                $clean[$key] = substr($value, 0, 250) . '... [truncated]';
            } else {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }
}

