<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * STEP 47: FacultyLens notification system.
 *
 * Extends Laravel's native `notifications` table (kept as the single storage so STEP 34 rows survive) with the
 * typed, queryable columns the notification center needs, and adds per-user, per-type delivery preferences.
 * Existing STEP 34 collaboration rows are back-filled into the new columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->string('category', 32)->default('SYSTEM')->after('type');
            $table->string('severity', 16)->default('INFO')->after('category');
            $table->string('title')->nullable()->after('severity');
            $table->text('message')->nullable()->after('title');
            $table->string('action_url', 1024)->nullable()->after('message');
            $table->string('entity_type', 64)->nullable()->after('action_url');
            $table->unsignedBigInteger('entity_id')->nullable()->after('entity_type');
            $table->string('dedupe_key', 191)->nullable()->after('entity_id');
            $table->timestamp('dismissed_at')->nullable()->after('read_at');
            $table->timestamp('expires_at')->nullable()->after('dismissed_at');
        });

        Schema::table('notifications', function (Blueprint $table) {
            // Bell / list / unread-count query shapes: WHERE user_id … AND dismissed_at IS NULL [AND read_at IS NULL] ORDER BY created_at DESC
            $table->index(['user_id', 'dismissed_at', 'created_at'], 'notifications_user_active_idx');
            $table->index(['user_id', 'read_at', 'dismissed_at'], 'notifications_user_unread_idx');
            $table->index(['user_id', 'category', 'created_at'], 'notifications_user_category_idx');
            $table->index(['user_id', 'type'], 'notifications_user_type_idx');
            $table->index('expires_at', 'notifications_expires_idx');
            // Retry-safe deduplication: the same event can never produce two rows for one user.
            $table->unique(['user_id', 'dedupe_key'], 'notifications_user_dedupe_unique');
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('notification_type', 64);
            $table->boolean('in_app_enabled')->default(true);
            $table->boolean('email_enabled')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'notification_type'], 'notification_prefs_user_type_unique');
        });

        $this->backfillCollaborationRows();
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropUnique('notifications_user_dedupe_unique');
            $table->dropIndex('notifications_user_active_idx');
            $table->dropIndex('notifications_user_unread_idx');
            $table->dropIndex('notifications_user_category_idx');
            $table->dropIndex('notifications_user_type_idx');
            $table->dropIndex('notifications_expires_idx');
        });
        Schema::table('notifications', function (Blueprint $table) {
            // MySQL: drop the FK before the column (BUG-012 ordering).
            $table->dropConstrainedForeignId('user_id');
        });
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropColumn(['category', 'severity', 'title', 'message', 'action_url', 'entity_type', 'entity_id', 'dedupe_key', 'dismissed_at', 'expires_at']);
        });
    }

    /**
     * STEP 34 rows stored everything in `data` (event/title/body/url). Promote them so the new
     * notification center can filter and render them like any other notification.
     */
    protected function backfillCollaborationRows(): void
    {
        $map = [
            'COLLABORATION_INVITATION_RECEIVED' => 'COLLABORATION_INVITATION',
            'COLLABORATION_INVITATION_ACCEPTED' => 'COLLABORATION_ACCEPTED',
            'COLLABORATION_INVITATION_DECLINED' => 'COLLABORATION_REJECTED',
            'COLLABORATOR_REMOVED' => 'COLLABORATION_REMOVED',
            'COLLABORATOR_ROLE_CHANGED' => 'COLLABORATION_ROLE_CHANGED',
            'COLLABORATION_COMMENT_ADDED' => 'COMMENT_CREATED',
            'MENTION_RECEIVED' => 'MENTION_RECEIVED',
        ];

        DB::table('notifications')->whereNull('user_id')->orderBy('created_at')->chunkById(200, function ($rows) use ($map) {
            foreach ($rows as $row) {
                $data = json_decode((string) $row->data, true) ?: [];
                $event = (string) ($data['event'] ?? '');
                $isUser = str_ends_with((string) $row->notifiable_type, 'User');
                DB::table('notifications')->where('id', $row->id)->update([
                    'user_id' => $isUser ? (int) $row->notifiable_id : null,
                    'type' => $map[$event] ?? ($event !== '' ? $event : $row->type),
                    'category' => 'COLLABORATION',
                    'severity' => 'INFO',
                    'title' => isset($data['title']) ? mb_substr((string) $data['title'], 0, 255) : null,
                    'message' => $data['body'] ?? null,
                    'action_url' => $data['url'] ?? null,
                    'entity_type' => isset($data['course_id']) ? 'course' : null,
                    'entity_id' => isset($data['course_id']) ? (int) $data['course_id'] : null,
                ]);
            }
        }, 'id');
    }
};
