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
        // nullable: NULL = user never chose → category default from config/email.php
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

    /** Security categories are always e-mailed (config email.mandatory_categories); AUTH e-mails are not notifications. */
    public static function isEmailMandatory(string $type): bool
    {
        $category = NotificationType::category($type);

        return $category !== null && in_array($category, (array) config('email.mandatory_categories', []), true);
    }

    /** Effective e-mail default for a type when the user never chose (config email.default_email_enabled per category). */
    public static function emailDefault(string $type): bool
    {
        $category = NotificationType::category($type);

        return $category !== null && (bool) config("email.default_email_enabled.{$category}", false);
    }

    /**
     * Whether the user receives this type by e-mail. A NULL stored flag means "never chosen" → category default.
     * Types the platform never e-mails (config email.never_email_types) are excluded regardless.
     */
    public static function emailEnabled(int $userId, string $type): bool
    {
        if (in_array($type, (array) config('email.never_email_types', []), true)) {
            return false;
        }
        if (self::isEmailMandatory($type)) {
            return true;
        }
        $row = static::query()->where('user_id', $userId)->where('notification_type', $type)->first(['email_enabled']);
        if ($row === null || $row->email_enabled === null) {
            return self::emailDefault($type);
        }

        return (bool) $row->email_enabled;
    }

    /**
     * Full matrix for the preferences UI: every registered type with its effective flags.
     *
     * @return array<int, array{notification_type:string, category:string, label:string, in_app_enabled:bool, email_enabled:bool, mandatory:bool, email_mandatory:bool, email_available:bool}>
     */
    public static function matrixFor(int $userId): array
    {
        $rows = static::query()->where('user_id', $userId)->get()->keyBy('notification_type');
        $never = (array) config('email.never_email_types', []);
        $out = [];
        foreach ((array) config('notifications.types') as $type => $def) {
            $mandatory = NotificationCategory::isMandatory($def[0]);
            $emailMandatory = in_array($def[0], (array) config('email.mandatory_categories', []), true);
            $emailAvailable = !in_array($type, $never, true);
            $row = $rows->get($type);
            $emailStored = $row?->email_enabled;
            $out[] = [
                'notification_type' => $type,
                'category' => $def[0],
                'label' => $def[2] ?? $type,
                'in_app_enabled' => $mandatory ? true : ($row?->in_app_enabled ?? true),
                'email_enabled' => $emailMandatory ? true : ($emailAvailable && ($emailStored === null ? self::emailDefault($type) : (bool) $emailStored)),
                'mandatory' => $mandatory,
                'email_mandatory' => $emailMandatory,
                'email_available' => $emailAvailable,
            ];
        }

        return $out;
    }
}
