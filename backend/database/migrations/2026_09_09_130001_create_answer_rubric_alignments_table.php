<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STEP 28: Answer <-> Rubric alignment analysis for a student answer.
 * Alignment is coverage evidence, not a grade; it never writes to student_answers.awarded_marks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('answer_rubric_alignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_answer_id')->constrained('student_answers')->cascadeOnDelete();
            $table->foreignId('student_submission_id')->constrained('student_submissions')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rubric_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('rubric_version')->nullable();
            $table->string('answer_fingerprint', 64)->nullable();
            $table->string('context_fingerprint', 64)->nullable();
            $table->decimal('overall_alignment_score', 5, 2)->nullable();   // mark-weighted %
            $table->decimal('unweighted_alignment_score', 5, 2)->nullable();
            $table->string('alignment_status', 20)->nullable();             // STRONG, PARTIAL, WEAK, NOT_ALIGNED
            $table->string('analysis_status', 20)->default('PENDING');      // PENDING, PROCESSING, COMPLETED, FAILED, REVIEWED, STALE
            $table->boolean('is_current')->default(true);
            $table->json('counts')->nullable();
            $table->text('summary')->nullable();
            $table->json('strengths')->nullable();
            $table->json('missing_elements')->nullable();
            $table->string('error_message', 500)->nullable();
            $table->string('model_name')->nullable();
            $table->string('model_version', 50)->nullable();
            $table->string('analysis_method', 40)->nullable();
            $table->json('thresholds')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['student_answer_id', 'is_current'], 'ara_answer_current_idx');
            $table->index(['student_answer_id', 'analysis_status'], 'ara_answer_status_idx');
            $table->index('student_submission_id', 'ara_submission_idx');
            $table->index('question_id', 'ara_question_idx');
            $table->index('rubric_id', 'ara_rubric_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('answer_rubric_alignments');
    }
};
