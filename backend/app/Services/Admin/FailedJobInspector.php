<?php

namespace App\Services\Admin;

/**
 * Safe view of failed_jobs rows. The serialized payload (which can hold e-mail context, one-time links or record ids)
 * is never returned; only the job class, queue and a scrubbed one-line exception summary are.
 *
 * Retry is allowed only for job classes whose side effects are safe to repeat.
 */
class FailedJobInspector
{
    /** @var array<string, array{retryable: bool, reason: string}> */
    public const RETRY_POLICY = [
        'App\\Jobs\\ProcessDocumentJob' => ['retryable' => true, 'reason' => 'Skips documents that already completed; re-extracts text otherwise.'],
        'App\\Jobs\\GenerateDocumentEmbeddingsJob' => ['retryable' => true, 'reason' => 'Rebuilds the document index from scratch; repeating it is harmless.'],
        'App\\Jobs\\AnalyzeAssessmentJob' => ['retryable' => true, 'reason' => 'Writes a new versioned analysis report; earlier versions are kept and AI output stays advisory.'],
        'App\\Jobs\\GenerateInstitutionalReportJob' => ['retryable' => true, 'reason' => 'Regenerates the same report record.'],
        'App\\Jobs\\StoreNotificationJob' => ['retryable' => true, 'reason' => 'Deduplicated on (user, dedupe key); a retry cannot create a second notification.'],
        'App\\Jobs\\GradeAnswerJob' => ['retryable' => true, 'reason' => 'Unique job producing an AI grading suggestion; faculty still decide the final mark.'],
        'App\\Jobs\\AnalyzeAnswerRubricAlignmentJob' => ['retryable' => true, 'reason' => 'Unique job producing advisory rubric-coverage evidence.'],
        'App\\Jobs\\AnalyzeStudentPerformanceJob' => ['retryable' => true, 'reason' => 'Unique job recomputing aggregates from finalized marks.'],
        'App\\Jobs\\SendFacultyLensEmailJob' => ['retryable' => false, 'reason' => 'The delivery is already marked failed, so a retry would exit without sending. Ask the user to repeat the action (for example request a new reset link).'],
        'App\\Jobs\\GenerateQuestionsJob' => ['retryable' => false, 'reason' => 'The request is marked failed when the job fails; faculty re-run generation from the question generator.'],
        'App\\Jobs\\RunAiEvaluationJob' => ['retryable' => false, 'reason' => 'Evaluation runs are recorded as failed; start a new run so results stay traceable.'],
    ];

    public static function summarize(object $row): array
    {
        $payload = json_decode((string) ($row->payload ?? ''), true) ?: [];
        $class = (string) ($payload['displayName'] ?? ($payload['data']['commandName'] ?? 'Unknown job'));
        $policy = self::RETRY_POLICY[$class] ?? ['retryable' => false, 'reason' => 'This job type is not on the safe-retry list.'];

        return [
            'id' => (int) ($row->id ?? 0),
            'uuid' => (string) ($row->uuid ?? ''),
            'job' => $class,
            'job_name' => class_basename($class),
            'queue' => (string) ($row->queue ?? ''),
            'connection' => (string) ($row->connection ?? ''),
            'attempts' => isset($payload['attempts']) ? (int) $payload['attempts'] : null,
            'exception' => self::exceptionSummary((string) ($row->exception ?? '')),
            'failed_at' => $row->failed_at ? \Illuminate\Support\Carbon::parse($row->failed_at)->toIso8601String() : null,
            'retryable' => $policy['retryable'],
            'retry_policy' => $policy['reason'],
        ];
    }

    /** "ClassName: first line" with paths, SQL, e-mail addresses and long tokens removed. */
    public static function exceptionSummary(string $exception): string
    {
        $first = trim(strtok($exception, "\n") ?: '');
        if ($first === '') {
            return 'No exception recorded.';
        }
        [$class, $message] = array_pad(explode(':', $first, 2), 2, '');
        $class = class_basename(trim($class));
        $message = trim($message);
        if (preg_match('/SQLSTATE|select |insert |update |delete from/i', $message)) {
            $message = 'Database error (details in the server log).';
        }
        $message = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email]', $message) ?? $message;
        $message = preg_replace('#([A-Za-z]:)?[\\\\/][^\s:]+\.(php|py)(:\d+)?#', '[path]', $message) ?? $message;
        $message = preg_replace('/\b[A-Za-z0-9_\-]{32,}\b/', '[token]', $message) ?? $message;
        $message = preg_replace('/\s+in\s+\[path\].*$/', '', $message) ?? $message;

        return mb_substr($message !== '' ? "{$class}: {$message}" : $class, 0, 200);
    }
}
