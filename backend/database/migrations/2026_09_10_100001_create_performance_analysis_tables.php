<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STEP 30: Reproducible snapshots of student performance / gap analysis per assessment.
 * Derived from finalized faculty marks only; marks themselves are never duplicated here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_analysis_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->decimal('expected_performance_percent', 5, 2);
            $table->unsignedInteger('minimum_responses');
            $table->json('thresholds')->nullable();
            $table->string('status', 20)->default('PENDING'); // PENDING, PROCESSING, COMPLETED, FAILED, STALE
            $table->boolean('is_current')->default(true);
            $table->string('grading_fingerprint', 64)->nullable(); // hash of the finalized grading data used
            $table->unsignedInteger('student_count')->default(0);
            $table->unsignedInteger('submission_count')->default(0);
            $table->unsignedInteger('finalized_answer_count')->default(0);
            $table->unsignedInteger('question_count')->default(0);
            $table->decimal('overall_average_percentage', 5, 2)->nullable();
            $table->decimal('overall_gap', 6, 2)->nullable();
            $table->string('overall_status', 20)->nullable();
            $table->json('summary')->nullable();
            $table->string('error_message', 500)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('analyzed_at')->nullable();
            $table->timestamps();

            $table->index(['assessment_id', 'is_current'], 'par_assessment_current_idx');
            $table->index(['assessment_id', 'status'], 'par_assessment_status_idx');
            $table->index('course_id', 'par_course_idx');
        });

        Schema::create('question_performance_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('performance_analysis_run_id')->constrained('performance_analysis_runs', 'id', 'qpr_run_fk')->cascadeOnDelete();
            $table->foreignId('question_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('question_number')->nullable();
            $table->string('question_text_excerpt', 255)->nullable();
            $table->decimal('maximum_marks', 8, 2);
            $table->unsignedInteger('response_count')->default(0);
            $table->unsignedInteger('submission_count')->default(0);
            $table->decimal('average_marks', 8, 2)->nullable();
            $table->decimal('average_percentage', 5, 2)->nullable();
            $table->decimal('median_marks', 8, 2)->nullable();
            $table->decimal('minimum_marks', 8, 2)->nullable();
            $table->decimal('max_awarded_marks', 8, 2)->nullable();
            $table->decimal('performance_gap', 6, 2)->nullable();
            $table->string('performance_status', 20);
            $table->string('difficulty_level', 20)->nullable();
            $table->string('cognitive_level', 30)->nullable();
            $table->json('topics')->nullable();
            $table->decimal('ai_suggested_average_percentage', 5, 2)->nullable();
            $table->decimal('rubric_alignment_average', 5, 2)->nullable();
            $table->json('review_signals')->nullable();
            $table->timestamps();

            $table->index(['performance_analysis_run_id', 'question_number'], 'qpr_run_question_idx');
            $table->index('question_id', 'qpr_question_idx');
        });

        Schema::create('topic_performance_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('performance_analysis_run_id')->constrained('performance_analysis_runs', 'id', 'tpr_run_fk')->cascadeOnDelete();
            $table->string('topic');
            $table->unsignedInteger('question_count')->default(0);
            $table->json('question_ids')->nullable();
            $table->unsignedInteger('response_count')->default(0);
            $table->decimal('total_marks', 10, 2)->nullable();
            $table->decimal('average_percentage', 5, 2)->nullable();
            $table->decimal('performance_gap', 6, 2)->nullable();
            $table->string('performance_status', 20);
            $table->timestamps();

            $table->index('performance_analysis_run_id', 'tpr_run_idx');
        });

        Schema::create('learning_outcome_performance_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('performance_analysis_run_id')->constrained('performance_analysis_runs', 'id', 'lopr_run_fk')->cascadeOnDelete();
            $table->foreignId('learning_outcome_id')->nullable()->constrained('learning_outcomes', 'id', 'lopr_lo_fk')->nullOnDelete();
            $table->string('lo_code', 50)->nullable();
            $table->string('lo_description', 500)->nullable();
            $table->unsignedInteger('question_count')->default(0);
            $table->json('question_ids')->nullable();
            $table->unsignedInteger('response_count')->default(0);
            $table->decimal('total_marks', 10, 2)->nullable();
            $table->decimal('average_percentage', 5, 2)->nullable();
            $table->decimal('performance_gap', 6, 2)->nullable();
            $table->string('performance_status', 20);
            $table->timestamps();

            $table->index('performance_analysis_run_id', 'lopr_run_idx');
            $table->index('learning_outcome_id', 'lopr_lo_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_outcome_performance_results');
        Schema::dropIfExists('topic_performance_results');
        Schema::dropIfExists('question_performance_results');
        Schema::dropIfExists('performance_analysis_runs');
    }
};
