<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STEP 38: Assessment Versioning — immutable historical snapshots of an assessment
 * (metadata, questions, blueprint, rubric references) plus backward-compatible version
 * links on analysis reports, generated reports, student submissions and student answers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('version_label', 20);
            $table->string('version_type', 10)->default('MAJOR'); // MAJOR | MINOR
            $table->string('status', 20)->default('DRAFT'); // DRAFT | IN_REVIEW | APPROVED | FINALIZED | ARCHIVED
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->text('instructions')->nullable();
            $table->string('assessment_type', 40);
            $table->decimal('total_marks', 8, 2)->default(0);
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->unsignedInteger('question_count')->default(0);
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('based_on_version_id')->nullable()->constrained('assessment_versions')->nullOnDelete();
            $table->text('change_summary')->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->string('validation_status', 30)->nullable(); // VALID | VALID_WITH_WARNINGS | INVALID
            $table->json('validation_result')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['assessment_id', 'version_number'], 'assessment_versions_assessment_version_unique');
            $table->index(['assessment_id', 'status'], 'assessment_versions_assessment_status_idx');
            $table->index('created_by', 'assessment_versions_created_by_idx');
        });

        Schema::create('assessment_version_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_version_id')->constrained('assessment_versions')->cascadeOnDelete();
            $table->foreignId('original_question_id')->nullable()->constrained('questions')->nullOnDelete();
            $table->unsignedInteger('question_number');
            $table->string('section_name', 150)->nullable();
            $table->text('question_text');
            $table->string('question_type', 40)->default('descriptive');
            $table->decimal('marks', 8, 2)->default(0);
            $table->string('difficulty_level', 20)->nullable();
            $table->string('cognitive_level', 20)->nullable();
            $table->string('topic', 255)->nullable();
            $table->foreignId('learning_outcome_id')->nullable()->constrained('learning_outcomes')->nullOnDelete();
            $table->foreignId('program_outcome_id')->nullable()->constrained('program_outcomes')->nullOnDelete();
            $table->text('expected_answer')->nullable();
            $table->json('rubric_snapshot')->nullable(); // {rubric_id, rubric_version, status, total_marks, criteria[]}
            $table->unsignedInteger('sort_order')->default(1);
            $table->timestamps();

            $table->index(['assessment_version_id', 'sort_order'], 'avq_version_sort_idx');
            $table->index('original_question_id', 'avq_original_question_idx');
        });

        Schema::create('assessment_version_blueprints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_version_id')->unique()->constrained('assessment_versions')->cascadeOnDelete();
            $table->foreignId('blueprint_id')->nullable()->constrained('assessment_blueprints')->nullOnDelete();
            $table->unsignedInteger('blueprint_version')->nullable();
            $table->string('blueprint_status', 20)->nullable();
            $table->string('validation_status', 30)->nullable();
            $table->decimal('total_marks', 8, 2)->default(0);
            $table->unsignedInteger('question_count')->default(0);
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->json('difficulty_distribution')->nullable(); // {easy: 30, medium: 50, hard: 20} (percent)
            $table->json('cognitive_distribution')->nullable();
            $table->json('learning_outcome_distribution')->nullable(); // {"lo:ID": {code, percentage}}
            $table->json('program_outcome_distribution')->nullable();
            $table->json('topic_distribution')->nullable();
            $table->json('question_type_distribution')->nullable();
            $table->json('sections')->nullable();
            $table->json('constraints')->nullable();
            $table->timestamps();
        });

        Schema::table('analysis_reports', function (Blueprint $table) {
            $table->foreignId('assessment_version_id')->nullable()->after('assessment_id')->constrained('assessment_versions')->nullOnDelete();
            $table->string('version_content_hash', 64)->nullable()->after('assessment_version_id');
        });

        Schema::table('assessment_reports', function (Blueprint $table) {
            $table->foreignId('assessment_version_id')->nullable()->after('analysis_report_id')->constrained('assessment_versions')->nullOnDelete();
        });

        // Backward compatible: existing submissions keep assessment_id only; new submissions record the version used.
        Schema::table('student_submissions', function (Blueprint $table) {
            $table->foreignId('assessment_version_id')->nullable()->after('assessment_id')->constrained('assessment_versions')->nullOnDelete();
        });

        Schema::table('student_answers', function (Blueprint $table) {
            $table->foreignId('assessment_version_question_id')->nullable()->after('question_id')->constrained('assessment_version_questions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('student_answers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assessment_version_question_id');
        });
        Schema::table('student_submissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assessment_version_id');
        });
        Schema::table('assessment_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assessment_version_id');
        });
        Schema::table('analysis_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assessment_version_id');
            $table->dropColumn('version_content_hash');
        });
        Schema::dropIfExists('assessment_version_blueprints');
        Schema::dropIfExists('assessment_version_questions');
        Schema::dropIfExists('assessment_versions');
    }
};
