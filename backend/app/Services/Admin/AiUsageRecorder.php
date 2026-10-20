<?php

namespace App\Services\Admin;

use App\Models\AiUsageLog;
use Closure;
use GuzzleHttp\Promise\Create;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Meters every call AiService makes to the FastAPI service (operation, status, latency, payload sizes).
 * The acting user comes from the session, or from Laravel Context for queued jobs dispatched by that user.
 */
class AiUsageRecorder
{
    public const CONTEXT_USER_KEY = 'acting_user_id';

    /** Guzzle middleware for Http::withMiddleware(); health probes are not metered. */
    public function middleware(): Closure
    {
        return function (callable $handler) {
            return function (RequestInterface $request, array $options) use ($handler) {
                $path = '/'.ltrim($request->getUri()->getPath(), '/');
                if (str_ends_with($path, '/health')) {
                    return $handler($request, $options);
                }
                $started = hrtime(true);
                $requestBytes = (int) ($request->getBody()->getSize() ?? 0);

                return $handler($request, $options)->then(
                    function (ResponseInterface $response) use ($path, $started, $requestBytes) {
                        $this->record($path, $response->getStatusCode(), $started, $requestBytes, (int) ($response->getBody()->getSize() ?? 0));

                        return $response;
                    },
                    function ($reason) use ($path, $started, $requestBytes) {
                        $this->record($path, null, $started, $requestBytes, 0);

                        return Create::rejectionFor($reason);
                    }
                );
            };
        };
    }

    public function record(string $path, ?int $status, int $startedHrtime, int $requestBytes, int $responseBytes): void
    {
        try {
            AiUsageLog::query()->create([
                'user_id' => $this->actingUserId(),
                'operation' => self::operationFor($path),
                'endpoint' => mb_substr($path, 0, 128),
                'status_code' => $status,
                'success' => $status !== null && $status >= 200 && $status < 300,
                'duration_ms' => (int) round((hrtime(true) - $startedHrtime) / 1_000_000),
                'request_bytes' => max(0, $requestBytes),
                'response_bytes' => max(0, $responseBytes),
                // Rough sub-word estimate (~4 characters per token); the AI service runs local models, so this is load, not spend
                'estimated_tokens' => (int) ceil((max(0, $requestBytes) + max(0, $responseBytes)) / 4),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // Metering must never break an AI request
            Log::debug('AI usage metering skipped: '.class_basename($e));
        }
    }

    /** Stable, human-readable operation name from the FastAPI path (e.g. /api/v1/chat/academic → chat_academic). */
    public static function operationFor(string $path): string
    {
        $segments = array_values(array_filter(explode('/', strtolower($path)), fn ($s) => $s !== '' && !in_array($s, ['api', 'v1', 'v2'], true) && !ctype_digit($s)));
        $name = implode('_', $segments) ?: 'root';

        return mb_substr(preg_replace('/[^a-z0-9_]+/', '_', $name), 0, 64);
    }

    protected function actingUserId(): ?int
    {
        try {
            $id = auth()->id() ?? Context::get(self::CONTEXT_USER_KEY);

            return $id !== null ? (int) $id : null;
        } catch (Throwable) {
            return null;
        }
    }
}
