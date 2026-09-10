<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STEP 34: Faculty Collaboration — course membership, hashed invitations, threaded comments,
 * Laravel database notifications, and a course_id on audit_logs for the per-course activity feed.
 * courses.user_id remains the single OWNER; course_collaborators holds additional members only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_collaborators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('role', 20); // EDITOR | REVIEWER | VIEWER (OWNER lives on courses.user_id)
            $table->string('status', 20)->default('ACTIVE'); // PENDING | ACTIVE | DECLINED | REVOKED
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['course_id', 'user_id'], 'course_collaborators_course_user_unique');
            $table->index(['user_id', 'status'], 'course_collaborators_user_status_idx');
            $table->index(['course_id', 'status'], 'course_collaborators_course_status_idx');
        });

        Schema::create('course_collaboration_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invited_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('invited_email');
            $table->foreignId('invited_by')->constrained('users')->cascadeOnDelete();
            $table->string('role', 20);
            $table->string('token_hash', 64)->unique();
            $table->string('status', 20)->default('PENDING'); // PENDING | ACCEPTED | DECLINED | REVOKED | EXPIRED
            $table->text('message')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['course_id', 'status'], 'collab_invitations_course_status_idx');
            $table->index(['invited_user_id', 'status'], 'collab_invitations_user_status_idx');
            $table->index(['invited_email', 'status'], 'collab_invitations_email_status_idx');
            $table->index('expires_at', 'collab_invitations_expires_idx');
        });

        Schema::create('collaboration_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('commentable_type', 40);
            $table->unsignedBigInteger('commentable_id');
            $table->foreignId('parent_id')->nullable()->constrained('collaboration_comments')->cascadeOnDelete();
            $table->text('body');
            $table->json('mentions')->nullable();
            $table->string('status', 20)->default('ACTIVE'); // ACTIVE | RESOLVED | DELETED
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['course_id', 'created_at'], 'collab_comments_course_created_idx');
            $table->index(['commentable_type', 'commentable_id', 'status'], 'collab_comments_target_idx');
            $table->index('parent_id', 'collab_comments_parent_idx');
            $table->index('user_id', 'collab_comments_user_idx');
        });

        if (!Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->morphs('notifiable');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreignId('course_id')->nullable()->after('entity_id')->constrained()->nullOnDelete();
            $table->index(['course_id', 'created_at'], 'audit_logs_course_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('audit_logs_course_created_idx');
            $table->dropConstrainedForeignId('course_id');
        });
        Schema::dropIfExists('collaboration_comments');
        Schema::dropIfExists('course_collaboration_invitations');
        Schema::dropIfExists('course_collaborators');
    }
};
