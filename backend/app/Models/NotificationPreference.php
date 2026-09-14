<?php

namespace App\Models;

use App\Notifications\NotificationCategory;
use App\Notifications\NotificationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * STEP 47: per-user, per-type delivery preference. Absence of a row means "enabled" (in-app) / "disabled" (email).
 * SECURITY and SYSTEM types are mandatory and ignore any stored row.
 */
class NotificationPreference extends Model
{
    protected $fillable = ['user_id', 'notification_type', 'in_app_enabled', 'email_enabled'];

    protected $casts = [
        'in_app_enabled' => 'boolean',
        'email_enabled' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function isMandatory(string $type): bool
    {
        $category = NotificationType::category($type);

        return $category !== null && NotificationCategory::isMandatory($category);
    }

    /** Whether the user receives this type in-app. */
    public static function inAppEnabled(int $userId, string $type): bool
    {
        if (self::isMandatory($type)) {
            return true;
        }
        $row = static::query()->where('user_id', $userId)->where('notification_type', $type)->first(['in_app_enabled']);

        return $row === null || $row->in_app_enabled;
    }

    /**
     * Full matrix for the preferences UI: every registered type with its effective flags.
     *
     * @return array<int, array{notification_type:string, category:string, label:string, in_app_enabled:bool, email_enabled:bool, mandatory:bool}>
     */
    public static function matrixFor(int $userId): array
    {
        $rows = static::query()->where('user_id', $userId)->get()->keyBy('notification_type');
        $out = [];
        foreach ((array) config('notifications.types') as $type => $def) {
            $mandatory = NotificationCategory::isMandatory($def[0]);
            $row = $rows->get($type);
            $out[] = [
                'notification_type' => $type,
                'category' => $def[0],
                'label' => $def[2] ?? $type,
                'in_app_enabled' => $mandatory ? true : ($row?->in_app_enabled ?? true),
                'email_enabled' => (bool) ($row?->email_enabled ?? false),
                'mandatory' => $mandatory,
            ];
        }

        return $out;
    }
}
