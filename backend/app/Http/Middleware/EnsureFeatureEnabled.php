<?php

namespace App\Http\Middleware;

use App\Services\Admin\FeatureFlagService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `feature:<key>`: reject new work while an admin has switched the feature off. Read-only requests (GET/HEAD)
 * pass, so existing results stay viewable.
 */
class EnsureFeatureEnabled
{
    public function __construct(protected FeatureFlagService $features) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if (!$request->isMethodSafe() && !$this->features->enabled($feature)) {
            $label = FeatureFlagService::DEFINITIONS[$feature]['label'] ?? 'This feature';

            return response()->json([
                'status' => 'error',
                'code' => 'FEATURE_DISABLED',
                'feature' => $feature,
                'message' => "{$label} is currently turned off by the administrator.",
            ], 403);
        }

        return $next($request);
    }
}
