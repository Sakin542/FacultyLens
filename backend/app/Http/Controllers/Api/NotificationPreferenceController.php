<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationPreference;
use App\Notifications\NotificationCategory;
use App\Notifications\NotificationType;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * STEP 47: per-type notification preferences. SECURITY / SYSTEM types are mandatory and cannot be disabled.
 * Email delivery flags are stored but only honoured where a mailer is configured (invitations only today).
 */
class NotificationPreferenceController extends Controller
{
    public function __construct(protected AuditLogService $audit) {}

    /** GET /api/notification-preferences */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'message' => 'Notification preferences retrieved.',
            'data' => [
                'preferences' => NotificationPreference::matrixFor($request->user()->id),
                'categories' => NotificationCategory::all(),
                'mandatory_categories' => (array) config('notifications.mandatory_categories'),
                'email_available' => (bool) config('email.enabled', true) && config('mail.default') !== null && config('mail.default') !== 'log' && config('mail.default') !== 'array',
                'email_mandatory_categories' => (array) config('email.mandatory_categories', []),
            ],
        ]);
    }

    /** PUT /api/notification-preferences  { preferences: [{notification_type, in_app_enabled, email_enabled?}] } */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'preferences' => ['required', 'array', 'min:1', 'max:' . count(NotificationType::all())],
            'preferences.*.notification_type' => ['required', 'string', Rule::in(NotificationType::all())],
            'preferences.*.in_app_enabled' => ['required', 'boolean'],
            'preferences.*.email_enabled' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();
        $changed = [];
        DB::transaction(function () use ($validated, $user, &$changed) {
            foreach ($validated['preferences'] as $row) {
                $changed[] = $this->apply($user->id, $row['notification_type'], (bool) $row['in_app_enabled'], isset($row['email_enabled']) ? (bool) $row['email_enabled'] : null);
            }
        });
        $this->audit->log('NOTIFICATION_PREFERENCE_UPDATED', 'NotificationPreference', null, ['types' => array_column($changed, 'notification_type'), 'count' => count($changed)], $user);

        return response()->json(['status' => 'success', 'message' => 'Notification preferences updated.', 'data' => ['preferences' => NotificationPreference::matrixFor($user->id)]]);
    }

    /** PATCH /api/notification-preferences/{type}  { in_app_enabled, email_enabled? } */
    public function patch(Request $request, string $type): JsonResponse
    {
        $type = strtoupper($type);
        if (!NotificationType::isValid($type)) {
            return response()->json(['status' => 'error', 'message' => 'Unknown notification type.'], 404);
        }
        $validated = $request->validate([
            'in_app_enabled' => ['required', 'boolean'],
            'email_enabled' => ['nullable', 'boolean'],
        ]);
        $user = $request->user();
        $row = $this->apply($user->id, $type, (bool) $validated['in_app_enabled'], isset($validated['email_enabled']) ? (bool) $validated['email_enabled'] : null);
        $this->audit->log('NOTIFICATION_PREFERENCE_UPDATED', 'NotificationPreference', null, ['types' => [$type], 'in_app_enabled' => $row['in_app_enabled']], $user);

        return response()->json(['status' => 'success', 'message' => 'Notification preference updated.', 'data' => $row]);
    }

    /** Mandatory types are always stored/returned as enabled regardless of the request (in-app and e-mail separately). */
    protected function apply(int $userId, string $type, bool $inApp, ?bool $email): array
    {
        $mandatory = NotificationPreference::isMandatory($type);
        $emailMandatory = NotificationPreference::isEmailMandatory($type);
        $pref = NotificationPreference::updateOrCreate(
            ['user_id' => $userId, 'notification_type' => $type],
            ['in_app_enabled' => $mandatory ? true : $inApp] + ($email !== null ? ['email_enabled' => $emailMandatory ? true : $email] : [])
        );

        return [
            'notification_type' => $type,
            'in_app_enabled' => (bool) $pref->in_app_enabled,
            'email_enabled' => NotificationPreference::emailEnabled($userId, $type),
            'mandatory' => $mandatory,
            'email_mandatory' => $emailMandatory,
        ];
    }
}
