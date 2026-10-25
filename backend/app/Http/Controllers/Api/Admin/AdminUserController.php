<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Admin\OperationTracker;
use App\Services\Admin\UserProvisioningService;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;

/**
 * Admin user management. Assignable roles are FACULTY and DEPARTMENT_HEAD: the ADMIN role belongs only to the
 * configured administrator account and cannot be granted, removed or deactivated from here, so the panel can never
 * lock its own administrator out. Suspending an account keeps every academic record it owns.
 */
class AdminUserController extends Controller
{
    public const ASSIGNABLE_ROLES = ['FACULTY', 'DEPARTMENT_HEAD'];

    public function __construct(protected AuditLogService $audit, protected UserProvisioningService $provisioning, protected OperationTracker $operations) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::in(['ADMIN', ...self::ASSIGNABLE_ROLES])],
            'status' => ['nullable', Rule::in(['active', 'deactivated'])],
            'department' => ['nullable', 'string', 'max:255'],
            'verified' => ['nullable', Rule::in(['yes', 'no'])],
            'two_factor' => ['nullable', Rule::in(['yes', 'no'])],
            'inactive_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'sort' => ['nullable', Rule::in(['name', 'email', 'created_at', 'last_login_at', 'courses_count'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $query = User::query()->withCount('courses');
        if ($term = trim((string) ($validated['search'] ?? ''))) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
            $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('email', 'like', $like)->orWhere('department', 'like', $like));
        }
        if (!empty($validated['role'])) {
            $query->where('role', $validated['role']);
        }
        match ($validated['status'] ?? null) {
            'deactivated' => $query->where('is_active', false),
            'active' => $query->where(fn ($q) => $q->where('is_active', true)->orWhereNull('is_active')),
            default => null,
        };
        if (!empty($validated['department'])) {
            $query->whereRaw('LOWER(TRIM(department)) = ?', [mb_strtolower(trim($validated['department']))]);
        }
        match ($validated['verified'] ?? null) {
            'yes' => $query->whereNotNull('email_verified_at'),
            'no' => $query->whereNull('email_verified_at'),
            default => null,
        };
        match ($validated['two_factor'] ?? null) {
            'yes' => $query->whereNotNull('two_factor_confirmed_at'),
            'no' => $query->whereNull('two_factor_confirmed_at'),
            default => null,
        };
        if (!empty($validated['inactive_days'])) {
            $cutoff = now()->subDays((int) $validated['inactive_days']);
            $query->where(fn ($q) => $q->where('last_login_at', '<', $cutoff)->orWhereNull('last_login_at'));
        }

        $sort = $validated['sort'] ?? null;
        $direction = $validated['direction'] ?? 'asc';
        if ($sort) {
            $query->orderBy($sort, $direction)->orderBy('id');
        } else {
            $query->orderByRaw("CASE WHEN role = 'ADMIN' THEN 0 ELSE 1 END")->orderBy('name');
        }

        $page = $query->paginate((int) ($validated['per_page'] ?? 20));

        return response()->json([
            'status' => 'success',
            'data' => collect($page->items())->map(fn (User $u) => $this->payload($u))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(User $user): JsonResponse
    {
        $user->loadCount(['courses', 'courseCollaborations']);
        $history = AuditLog::query()->with('user:id,name')
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('entity_type', 'User')->where('entity_id', $user->id))
                ->orWhere(fn ($w) => $w->where('user_id', $user->id)->whereIn('action', ['AUTH_LOGIN_FAILED', 'ADMIN_LOGIN_REFUSED', 'PASSWORD_CHANGED', 'PASSWORD_RESET'])))
            ->latest('created_at')->limit(50)->get()
            ->map(fn (AuditLog $l) => [
                'id' => $l->id,
                'action' => $l->action,
                'actor' => $l->user?->only(['id', 'name']),
                'metadata' => $l->metadata,
                'created_at' => $l->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'status' => 'success',
            'data' => $this->payload($user) + [
                'collaborations_count' => (int) $user->course_collaborations_count,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'sessions' => $this->sessionSummary($user),
                'history' => $history,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'department' => ['nullable', 'string', 'max:255'],
            'designation' => ['nullable', 'string', 'max:255'],
            'role' => ['nullable', Rule::in(self::ASSIGNABLE_ROLES)],
            'send_invite' => ['nullable', 'boolean'],
        ]);
        $validated['email'] = strtolower(trim($validated['email']));
        if (User::query()->whereRaw('LOWER(email) = ?', [$validated['email']])->exists()) {
            return response()->json(['status' => 'error', 'message' => 'An account with this e-mail already exists.', 'errors' => ['email' => ['An account with this e-mail already exists.']]], 422);
        }

        $user = $this->provisioning->create($validated, $request->user(), $request->boolean('send_invite', true));

        return response()->json([
            'status' => 'success',
            'message' => $request->boolean('send_invite', true)
                ? "Account created. {$user->email} received a link to set their password."
                : 'Account created. Send a password link when the user is ready to sign in.',
            'data' => $this->payload($user->loadCount('courses')),
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'role' => ['sometimes', 'string', Rule::in(self::ASSIGNABLE_ROLES)],
            'is_active' => ['sometimes', 'boolean'],
            'name' => ['sometimes', 'string', 'max:255'],
            'department' => ['sometimes', 'nullable', 'string', 'max:255'],
            'designation' => ['sometimes', 'nullable', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        if ($this->isProtected($user)) {
            return response()->json(['status' => 'error', 'message' => 'The administrator account cannot be changed from the admin panel.'], 422);
        }
        if (($validated['is_active'] ?? null) === false && $user->isActive() && trim((string) ($validated['reason'] ?? '')) === '') {
            return response()->json(['status' => 'error', 'message' => 'Give a reason for suspending this account.', 'errors' => ['reason' => ['A reason is required to suspend an account.']]], 422);
        }

        $changes = $this->applyChanges($user, $validated);
        if ($changes !== []) {
            $this->audit->log(isset($changes['is_active']) ? ($user->isActive() ? 'ADMIN_USER_REINSTATED' : 'ADMIN_USER_SUSPENDED') : 'ADMIN_USER_UPDATED', $user, $user->id,
                ['changes' => $changes, 'reason' => $validated['reason'] ?? null], $request->user());
            if (isset($changes['role'])) {
                $this->audit->log('ADMIN_ROLE_CHANGED', $user, $user->id, ['from' => $changes['role']['from'], 'to' => $changes['role']['to'], 'reason' => $validated['reason'] ?? null], $request->user());
            }
        }

        return response()->json(['status' => 'success', 'message' => $changes ? 'User updated.' : 'Nothing to change.', 'data' => $this->payload($user->loadCount('courses'))]);
    }

    /** Apply one bulk action to many accounts in a single transaction. The administrator account is always skipped. */
    public function bulk(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer', 'distinct'],
            'action' => ['required', Rule::in(['activate', 'deactivate', 'set_role', 'set_department'])],
            'role' => ['required_if:action,set_role', 'nullable', Rule::in(self::ASSIGNABLE_ROLES)],
            'department' => ['required_if:action,set_department', 'nullable', 'string', 'max:255'],
            'reason' => ['required_if:action,deactivate', 'nullable', 'string', 'max:500'],
            'confirm' => ['required', 'accepted'],
        ]);

        $users = User::query()->whereIn('id', $validated['ids'])->get();
        $skipped = $users->filter(fn (User $u) => $this->isProtected($u))->pluck('id')->all();
        $targets = $users->reject(fn (User $u) => $this->isProtected($u));
        $attributes = match ($validated['action']) {
            'activate' => ['is_active' => true],
            'deactivate' => ['is_active' => false],
            'set_role' => ['role' => $validated['role']],
            'set_department' => ['department' => $validated['department']],
        };

        $changed = DB::transaction(function () use ($targets, $attributes, $validated, $request) {
            $changed = 0;
            foreach ($targets as $user) {
                $changes = $this->applyChanges($user, $attributes);
                if ($changes) {
                    $changed++;
                    $this->audit->log('ADMIN_USER_BULK_UPDATED', $user, $user->id, ['action' => $validated['action'], 'changes' => $changes, 'reason' => $validated['reason'] ?? null], $request->user());
                }
            }

            return $changed;
        });

        return response()->json([
            'status' => 'success',
            'message' => "{$changed} account(s) updated".($skipped ? '; the administrator account was skipped.' : '.'),
            'data' => ['updated' => $changed, 'unchanged' => $targets->count() - $changed, 'skipped' => $skipped, 'not_found' => array_values(array_diff($validated['ids'], $users->pluck('id')->all()))],
        ]);
    }

    public function revokeSessions(Request $request, User $user): JsonResponse
    {
        if ($this->isProtected($user)) {
            return response()->json(['status' => 'error', 'message' => 'End your own sessions by signing out.'], 422);
        }
        $ended = $this->endSessions($user);
        $this->audit->log('ADMIN_SESSIONS_REVOKED', $user, $user->id, $ended, $request->user());

        return response()->json(['status' => 'success', 'message' => 'All sessions for this account were ended.', 'data' => $ended]);
    }

    /**
     * Permanently delete an account. Refused while the user still owns courses: deleting them would cascade to
     * assessments and student records, so such accounts are suspended instead (or their courses removed first).
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'confirm_email' => ['required', 'string'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        if ($this->isProtected($user) || $user->is($request->user())) {
            return response()->json(['status' => 'error', 'message' => 'The administrator account cannot be deleted.'], 422);
        }
        if (strtolower(trim($validated['confirm_email'])) !== strtolower((string) $user->email)) {
            return response()->json(['status' => 'error', 'message' => 'Type the account\'s e-mail address exactly to confirm.',
                'errors' => ['confirm_email' => ['The e-mail address does not match this account.']]], 422);
        }
        $courses = $user->courses()->count();
        if ($courses > 0) {
            return response()->json(['status' => 'error', 'message' => "This account owns {$courses} course(s). Deleting it would also delete their assessments and student records, so suspend the account instead."], 409);
        }

        $snapshot = ['email' => $user->email, 'name' => $user->name, 'role' => $user->role, 'reason' => $validated['reason']];
        DB::transaction(function () use ($user, $snapshot, $request) {
            $this->audit->log('ADMIN_USER_DELETED', $user, $user->id, $snapshot, $request->user());
            $this->endSessions($user);
            $user->delete();
        });

        return response()->json(['status' => 'success', 'message' => "The account {$snapshot['email']} was deleted."]);
    }

    /** E-mail the user a standard password-reset link (the admin never sees or sets passwords). */
    public function sendPasswordReset(Request $request, User $user): JsonResponse
    {
        $status = Password::broker()->sendResetLink(['email' => $user->email]);
        $this->audit->log('ADMIN_PASSWORD_RESET_SENT', $user, $user->id, ['result' => $status], $request->user());

        return $status === Password::RESET_LINK_SENT
            ? response()->json(['status' => 'success', 'message' => "A password reset link was sent to {$user->email}."])
            : response()->json(['status' => 'error', 'message' => 'A reset link was sent recently. Please wait before trying again.'], 429);
    }

    /** Reset a user's 2FA when they lose their phone and recovery codes. */
    public function resetTwoFactor(Request $request, User $user): JsonResponse
    {
        if ($user->isAdmin()) {
            return response()->json(['status' => 'error', 'message' => 'Manage the administrator\'s two-factor settings from Settings.'], 422);
        }
        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
        $this->audit->log('ADMIN_TWO_FACTOR_RESET', $user, $user->id, null, $request->user());

        return response()->json(['status' => 'success', 'message' => 'Two-factor authentication was reset for this user.', 'data' => $this->payload($user->loadCount('courses'))]);
    }

    public function importPreview(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:'.(int) config('admin.imports.max_kilobytes', 512)]]);

        return response()->json(['status' => 'success', 'data' => $this->provisioning->preview((string) $request->file('file')->get())]);
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:'.(int) config('admin.imports.max_kilobytes', 512)],
            'send_invites' => ['nullable', 'boolean'],
            'confirm' => ['required', 'accepted'],
        ]);
        $contents = (string) $request->file('file')->get();
        $name = mb_substr((string) $request->file('file')->getClientOriginalName(), 0, 80);

        $op = $this->operations->run('user_import', "Import accounts from {$name}", $request->user(), function () use ($contents, $request) {
            $result = $this->provisioning->import($contents, $request->user(), $request->boolean('send_invites', true));

            return ['total' => $result['created'] + $result['skipped'], 'success' => $result['created'], 'failure' => $result['skipped'], 'summary' => ['skipped_rows' => $result['skipped_rows']]];
        });
        $this->audit->log('ADMIN_USERS_IMPORTED', 'AdminOperation', $op->id, ['created' => $op->success_count, 'skipped' => $op->failure_count, 'status' => $op->status], $request->user());

        return response()->json([
            'status' => $op->status === 'completed' ? 'success' : 'error',
            'message' => $op->status === 'completed' ? "{$op->success_count} account(s) created, {$op->failure_count} row(s) skipped." : ($op->error ?? 'The import failed.'),
            'data' => $op->load('initiator:id,name')->toPayload(),
        ], $op->status === 'completed' ? 200 : 422);
    }

    // ------------------------------------------------------------------------------------------------ helpers

    protected function isProtected(User $user): bool
    {
        return $user->isAdmin() || strtoupper((string) $user->role) === 'ADMIN';
    }

    /** @return array<string, array{from: mixed, to: mixed}> */
    protected function applyChanges(User $user, array $input): array
    {
        $changes = [];
        foreach (['role', 'name', 'department', 'designation'] as $field) {
            if (array_key_exists($field, $input)) {
                $value = is_string($input[$field]) ? trim($input[$field]) : $input[$field];
                $value = $value === '' && $field !== 'name' ? null : $value;
                if ($value !== $user->{$field} && !($field === 'name' && $value === '')) {
                    $changes[$field] = ['from' => $user->{$field}, 'to' => $value];
                    $user->{$field} = $value;
                }
            }
        }
        if (array_key_exists('is_active', $input) && (bool) $input['is_active'] !== $user->isActive()) {
            $changes['is_active'] = ['from' => $user->isActive(), 'to' => (bool) $input['is_active']];
            $user->is_active = (bool) $input['is_active'];
        }
        if ($changes !== []) {
            $user->save();
            if (isset($changes['is_active']) && !$user->isActive()) {
                $this->endSessions($user);
            }
        }

        return $changes;
    }

    /** @return array{tokens: int, sessions: int} */
    protected function endSessions(User $user): array
    {
        $tokens = method_exists($user, 'tokens') ? $user->tokens()->delete() : 0;
        $user->forceFill(['remember_token' => null])->saveQuietly();
        $sessions = 0;
        if (config('session.driver') === 'database') {
            $sessions = DB::table((string) config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }

        return ['tokens' => (int) $tokens, 'sessions' => (int) $sessions];
    }

    protected function sessionSummary(User $user): array
    {
        $driver = (string) config('session.driver');
        if ($driver !== 'database') {
            return ['tracked' => false, 'driver' => $driver, 'active' => null, 'last_activity_at' => null, 'api_tokens' => $user->tokens()->count()];
        }
        $rows = DB::table((string) config('session.table', 'sessions'))->where('user_id', $user->id)->get(['last_activity']);

        return [
            'tracked' => true,
            'driver' => $driver,
            'active' => $rows->count(),
            'last_activity_at' => $rows->max('last_activity') ? \Illuminate\Support\Carbon::createFromTimestamp((int) $rows->max('last_activity'))->toIso8601String() : null,
            'api_tokens' => $user->tokens()->count(),
        ];
    }

    protected function payload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role ?? 'FACULTY',
            'is_admin' => $user->isAdmin(),
            'is_active' => $user->isActive(),
            'department' => $user->department,
            'designation' => $user->designation,
            'courses_count' => (int) ($user->courses_count ?? 0),
            'two_factor_enabled' => $user->hasTwoFactorEnabled(),
            'google_linked' => !empty($user->google_id),
            'email_verified' => $user->email_verified_at !== null,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
            'profile_picture_url' => $user->profile_picture_url,
        ];
    }
}
