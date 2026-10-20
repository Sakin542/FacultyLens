<?php

namespace App\Services\Admin;

use App\Http\Controllers\Api\Admin\AdminUserController;
use App\Models\User;
use App\Services\AuditLogService;
use App\Support\SafeCsv;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Admin account provisioning. Accounts are created with an unusable random password and the user receives a standard
 * password-reset link to choose their own; the administrator never sees or sets a password.
 */
class UserProvisioningService
{
    public const IMPORT_COLUMNS = ['name', 'email', 'department', 'designation', 'role'];

    public function __construct(protected AuditLogService $audit) {}

    /** @param array{name: string, email: string, department?: ?string, designation?: ?string, role?: ?string} $data */
    public function create(array $data, User $by, bool $sendInvite = true, string $source = 'manual'): User
    {
        $user = DB::transaction(function () use ($data) {
            $user = User::query()->create([
                'name' => trim($data['name']),
                'email' => strtolower(trim($data['email'])),
                'department' => $this->nullable($data['department'] ?? null),
                'designation' => $this->nullable($data['designation'] ?? null),
                'password' => Str::random(48),
            ]);
            $user->forceFill(['role' => $data['role'] ?? 'FACULTY', 'is_active' => true])->save();

            return $user;
        });

        $invite = $sendInvite ? Password::broker()->sendResetLink(['email' => $user->email]) : null;
        $this->audit->log('ADMIN_USER_CREATED', $user, $user->id, [
            'role' => $user->role,
            'source' => $source,
            'invitation' => $invite === null ? 'not_sent' : ($invite === Password::RESET_LINK_SENT ? 'sent' : 'throttled'),
        ], $by);

        return $user;
    }

    /**
     * Validate an uploaded CSV without writing anything.
     *
     * @return array{columns: list<string>, missing_columns: list<string>, rows: list<array>, valid: int, invalid: int, truncated: bool}
     */
    public function preview(string $contents): array
    {
        $max = (int) config('admin.imports.max_rows', 500);
        $parsed = SafeCsv::parse($contents, $max + 1);
        $missing = array_values(array_diff(['name', 'email'], $parsed['header']));
        $truncated = count($parsed['rows']) > $max;
        $rows = array_slice($parsed['rows'], 0, $max);

        if ($missing) {
            return ['columns' => $parsed['header'], 'missing_columns' => $missing, 'rows' => [], 'valid' => 0, 'invalid' => 0, 'truncated' => false];
        }

        $emails = array_map(fn ($r) => strtolower(trim($r['email'] ?? '')), $rows);
        $existing = User::query()->whereIn(DB::raw('LOWER(email)'), array_filter($emails))->pluck('email')->map(fn ($e) => strtolower($e))->flip();
        $seen = [];
        $out = [];
        foreach ($rows as $i => $row) {
            $email = $emails[$i];
            $role = strtoupper(trim($row['role'] ?? '')) ?: 'FACULTY';
            $errors = [];
            if (trim($row['name'] ?? '') === '') {
                $errors[] = 'Name is required.';
            } elseif (mb_strlen($row['name']) > 255) {
                $errors[] = 'Name is too long.';
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'E-mail address is invalid.';
            } elseif (isset($existing[$email])) {
                $errors[] = 'An account with this e-mail already exists.';
            } elseif (isset($seen[$email])) {
                $errors[] = 'Duplicate of row '.$seen[$email].' in this file.';
            }
            if (!in_array($role, AdminUserController::ASSIGNABLE_ROLES, true)) {
                $errors[] = 'Role must be FACULTY or DEPARTMENT_HEAD.';
            }
            if (mb_strlen($row['department'] ?? '') > 255 || mb_strlen($row['designation'] ?? '') > 255) {
                $errors[] = 'Department/designation is too long.';
            }
            $seen[$email] ??= $i + 2;
            $out[] = [
                'line' => $i + 2,
                'name' => mb_substr($row['name'] ?? '', 0, 255),
                'email' => $email,
                'department' => $this->nullable($row['department'] ?? null),
                'designation' => $this->nullable($row['designation'] ?? null),
                'role' => $role,
                'errors' => $errors,
            ];
        }
        $valid = count(array_filter($out, fn ($r) => $r['errors'] === []));

        return ['columns' => $parsed['header'], 'missing_columns' => [], 'rows' => $out, 'valid' => $valid, 'invalid' => count($out) - $valid, 'truncated' => $truncated];
    }

    /**
     * Re-validate the same file and create the valid rows. Invalid rows are skipped and reported, never forced in.
     *
     * @return array{created: int, skipped: int, skipped_rows: list<array{line: int, errors: list<string>}>}
     */
    public function import(string $contents, User $by, bool $sendInvites): array
    {
        $preview = $this->preview($contents);
        if ($preview['missing_columns']) {
            throw new AdminOperationException('Missing required column(s): '.implode(', ', $preview['missing_columns']).'.');
        }
        $created = 0;
        $skipped = [];
        foreach ($preview['rows'] as $row) {
            if ($row['errors']) {
                $skipped[] = ['line' => $row['line'], 'errors' => $row['errors']];
                continue;
            }
            $this->create($row, $by, $sendInvites, 'csv_import');
            $created++;
        }

        return ['created' => $created, 'skipped' => count($skipped), 'skipped_rows' => array_slice($skipped, 0, 100)];
    }

    protected function nullable(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 255);
    }
}
