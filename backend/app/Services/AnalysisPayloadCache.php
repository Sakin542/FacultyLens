<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * STEP 43 — cache for the assembled `GET /ai/assessments/{id}/analysis` payload.
 *
 * Keyed by assessment (not by user): authorization is enforced before the cache is read and the payload contains
 * no user-specific fields. Measured under load, per-user keys duplicated a ~115 KB payload once per collaborator
 * and exhausted Redis (allkeys-lru evictions) while leaving other collaborators with a stale copy after a re-run.
 */
class AnalysisPayloadCache
{
    public const TTL_SECONDS = 300;

    public static function key(int $assessmentId): string
    {
        return "assessment:{$assessmentId}:analysis";
    }

    public static function get(int $assessmentId): ?array
    {
        $cached = Cache::get(self::key($assessmentId));

        return is_array($cached) ? $cached : null;
    }

    public static function put(int $assessmentId, array $payload): void
    {
        Cache::put(self::key($assessmentId), $payload, self::TTL_SECONDS);
    }

    /** Call whenever anything shown on the analysis page changes: analysis run, recommendation decision, question set. */
    public static function forget(int $assessmentId): void
    {
        Cache::forget(self::key($assessmentId));
    }
}
