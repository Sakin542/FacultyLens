<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin panel storage: runtime feature toggles, AI usage metering and database backup history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->json('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('ai_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('operation', 64)->index();
            $table->string('endpoint', 128);
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->boolean('success')->default(false)->index();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->unsignedInteger('request_bytes')->default(0);
            $table->unsignedInteger('response_bytes')->default(0);
            $table->unsignedInteger('estimated_tokens')->default(0);
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('database_backups', function (Blueprint $table) {
            $table->id();
            $table->string('filename', 191)->unique();
            $table->string('disk', 32)->default('local');
            $table->string('path', 255);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('status', 16)->default('running')->index(); // running | completed | failed
            $table->string('trigger', 16)->default('manual');           // manual | scheduled
            $table->string('driver', 16)->nullable();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('error', 255)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('database_backups');
        Schema::dropIfExists('ai_usage_logs');
        Schema::dropIfExists('system_settings');
    }
};
