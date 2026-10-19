<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Hard stop before any trait (RefreshDatabase!) runs: the suite must only ever touch an in-memory SQLite
     * database. Running `php artisan test` inside the Docker app container once picked up the container's real
     * DB_* variables and migrate:fresh wiped the development MySQL database.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $default = $app['config']->get('database.default');
        $database = $app['config']->get("database.connections.{$default}.database");
        if ($default !== 'sqlite' || $database !== ':memory:') {
            throw new \RuntimeException(
                "Refusing to run tests against database connection '{$default}' ({$database}). "
                .'Tests must use the in-memory SQLite database configured in phpunit.xml.'
            );
        }

        return $app;
    }

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
