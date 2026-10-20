<?php

namespace App\Http\Middleware;

use App\Services\Admin\AiUsageRecorder;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends the session of an account an admin deactivated, and records the acting user in Laravel Context so queued
 * jobs dispatched by this request (AI analysis, reports) are attributed to them in the AI usage log.
 */
class EnsureAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('sanctum');
        if ($user) {
            if (method_exists($user, 'isActive') && !$user->isActive()) {
                Auth::guard('web')->logout();
                if ($request->hasSession()) {
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();
                }

                return response()->json([
                    'status' => 'error',
                    'code' => 'ACCOUNT_DEACTIVATED',
                    'message' => 'This account has been deactivated. Contact your administrator.',
                ], 401);
            }
            Context::add(AiUsageRecorder::CONTEXT_USER_KEY, $user->id);
        }

        return $next($request);
    }
}
