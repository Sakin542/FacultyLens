<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** STEP 39: institutional report requests + generated private files (filters/version are snapshotted for traceability). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('institutional_reports', function (Blueprint $table) {
            $table->id();
            $table->uuid('report_uuid')->unique();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('report_type', 40);
            $table->string('scope_type', 30);
            $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assessment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assessment_version_id')->nullable()->constrained('assessment_versions')->nullOnDelete();
            $table->string('department', 255)->nullable();
            $table->foreignId('program_id')->nullable()->constrained()->nullOnDelete();
            $table->json('filters')->nullable();
            $table->string('title', 255);
            $table->string('format', 10);
            $table->string('status', 20)->default('PENDING');
            $table->boolean('is_async')->default(false);
            $table->boolean('contains_student_data')->default(false);
            $table->string('file_path', 500)->nullable();
            $table->string('file_name', 255)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->unsignedInteger('record_count')->nullable();
            $table->json('summary')->nullable();
            $table->timestamp('data_as_of')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('file_deleted_at')->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamps();

            $table->index(['created_by', 'created_at'], 'inst_reports_creator_created_idx');
            $table->index(['status', 'expires_at'], 'inst_reports_status_expires_idx');
            $table->index(['report_type', 'scope_type'], 'inst_reports_type_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('institutional_reports');
    }
};
