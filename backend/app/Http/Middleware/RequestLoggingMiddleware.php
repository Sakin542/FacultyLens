<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Structured request logging: one line per API request with request id, user id, route, status and
 * duration. The request id is echoed as X-Request-Id and shared with every log line written during the
 * request. Bodies, headers, cookies and query values are never logged.
 */
class RequestLoggingMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);
        $incoming = (string) $request->headers->get('X-Request-Id', '');
        $requestId = preg_match('/^[A-Za-z0-9._-]{8,64}$/', $incoming) ? $incoming : (string) Str::uuid();
        $request->attributes->set('request_id', $requestId);
        Log::shareContext(['request_id' => $requestId]);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        $durationMs = (int) round((microtime(true) - $start) * 1000);
        $route = $request->route();
        $context = [
            'request_id' => $requestId,
            'method' => $request->getMethod(),
            'route' => $route?->uri() ?? $request->path(),
            'status' => $response->getStatusCode(),
            'duration_ms' => $durationMs,
            'user_id' => $request->user()?->id,
            'ip' => $request->ip(),
        ];
        $status = $response->getStatusCode();
        if ($status >= 500) {
            Log::error('api.request', $context);
        } elseif ($status >= 400 || $durationMs >= (int) config('logging.slow_request_ms', 2000)) {
            Log::warning('api.request', $context);
        } elseif (config('logging.log_requests', true)) {
            Log::info('api.request', $context);
        }

        return $response;
    }
}
