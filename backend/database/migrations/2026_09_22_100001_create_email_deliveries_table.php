<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outbound e-mail delivery tracking. One row per (recipient, event) — the idempotency key makes queue retries and
 * duplicate producer events collapse onto a single message. Only metadata is stored: never SMTP credentials and never
 * the rendered body.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('notification_id')->nullable();
            $table->string('type', 64);
            $table->string('category', 32)->nullable();
            $table->string('template', 64);
            $table->string('recipient', 190);
            $table->string('subject', 255);
            $table->string('status', 16)->default('PENDING');
            $table->string('provider', 32)->default('smtp');
            $table->string('idempotency_key', 191)->unique('email_deliveries_idempotency_unique');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('message_id', 255)->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at'], 'email_deliveries_user_created_idx');
            $table->index('notification_id', 'email_deliveries_notification_idx');
            $table->index(['status', 'created_at'], 'email_deliveries_status_created_idx');
            $table->index(['user_id', 'type', 'created_at'], 'email_deliveries_user_type_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_deliveries');
    }
};
