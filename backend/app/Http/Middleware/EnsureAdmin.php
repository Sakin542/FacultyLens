<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Admin panel gate: the session user must be the configured administrator (role ADMIN + ADMIN_EMAIL). */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (!$user || !$user->isAdmin()) {
            return response()->json([
                'status' => 'error',
                'message' => 'The admin panel is restricted to the FacultyLens administrator.',
            ], 403);
        }

        return $next($request);
    }
}
