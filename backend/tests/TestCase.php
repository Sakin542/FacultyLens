<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Skip (rather than fail) tests that intentionally exercise the real FastAPI service when it is not reachable.
     */
    protected function requireLiveAiService(): void
    {
        $baseUrl = rtrim((string) config('services.ai.url', 'http://127.0.0.1:8001'), '/');
        $ctx = stream_context_create(['http' => ['timeout' => 3]]);
        $health = @file_get_contents("{$baseUrl}/health", false, $ctx);
        $body = $health !== false ? json_decode($health, true) : null;
        if (!is_array($body) || !in_array($body['status'] ?? null, ['ok', 'healthy', 'success'], true)) {
            $this->markTestSkipped("Live AI service is not reachable on {$baseUrl} (start it with `docker compose up -d ai-service`).");
        }
    }
}
