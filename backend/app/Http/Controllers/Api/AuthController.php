<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * Register a new faculty user
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => trim($request->name),
            'email' => strtolower(trim($request->email)),
            'department' => trim($request->department),
            'designation' => trim($request->designation),
            'password' => Hash::make($request->password),
        ]);

        // Authenticate the registered user using Sanctum session
        Auth::login($user);
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Registration successful',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'department' => $user->department,
                'designation' => $user->designation,
            ],
        ], 201);
    }

    /**
     * Authenticate faculty member and establish session
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = [
            'email' => strtolower(trim($request->email)),
            'password' => $request->password,
        ];

        if (! Auth::attempt($credentials, $request->boolean('remember', false))) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid email or password',
            ], 401);
        }

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }
        $user = Auth::user();

        return response()->json([
            'status' => 'success',
            'message' => 'Login successful',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'department' => $user->department,
                'designation' => $user->designation,
            ],
        ]);
    }

    /**
     * Get the currently authenticated faculty member
     */
    public function user(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'status' => 'success',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role ?? 'FACULTY',
                'department' => $user->department,
                'designation' => $user->designation,
            ],
        ]);
    }

    /**
     * Update user profile with strict protection against privilege escalation.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'department' => ['sometimes', 'string', 'max:255'],
            'designation' => ['sometimes', 'string', 'max:255'],
        ]);

        // Explicit role escalation defense: only an existing ADMIN can alter roles
        if ($request->has('role') && $user->isAdmin()) {
            $user->role = strtoupper($request->input('role'));
        }

        // Email is the institutional identity and is never editable here (silently ignored if sent).
        $user->fill($validated);
        $changed = array_keys($user->getDirty());
        $user->save();
        if ($changed !== []) {
            app(\App\Services\AuditLogService::class)->log('PROFILE_UPDATED', $user, $user->id, ['fields' => $changed], $user);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Profile updated successfully.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role ?? 'FACULTY',
                'department' => $user->department,
                'designation' => $user->designation,
            ],
        ]);
    }

    /**
     * Change the authenticated user's password (requires the current password).
     */
    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:6', 'confirmed', 'different:current_password'],
        ], [
            'password.different' => 'The new password must be different from the current password.',
        ]);

        if (!Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'status' => 'error',
                'message' => 'The current password is incorrect.',
                'errors' => ['current_password' => ['The current password is incorrect.']],
            ], 422);
        }

        $user->password = Hash::make($validated['password']);
        $user->save();

        // Other sessions/tokens are invalidated; the current session stays signed in.
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }
        if (method_exists($user, 'tokens')) {
            $user->tokens()->delete();
        }
        app(\App\Services\AuditLogService::class)->log('PASSWORD_CHANGED', $user, $user->id, null, $user);

        return response()->json([
            'status' => 'success',
            'message' => 'Password updated successfully.',
        ]);
    }

    /**
     * Log the faculty member out and invalidate session
     */
    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Logout successful',
        ]);
    }
}

