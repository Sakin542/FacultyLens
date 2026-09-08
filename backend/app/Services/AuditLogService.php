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
            'metadata' => $sanitizedMetadata,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent() ? substr(request()->userAgent(), 0, 255) : null,
            'created_at' => now(),
        ]);
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

