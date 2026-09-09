<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STEP 27: AI grading suggestions for a student answer.
 * Suggested marks are stored separately from faculty marks (student_answers.awarded_marks).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_grading_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_answer_id')->constrained('student_answers')->cascadeOnDelete();
            $table->foreignId('student_submission_id')->constrained('student_submissions')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            // Historical results keep pointing at the rubric version that was actually used.
            $table->foreignId('rubric_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('rubric_version')->nullable();
            $table->string('answer_fingerprint', 64)->nullable();   // sha256 of the evaluated answer content
            $table->string('context_fingerprint', 64)->nullable();  // sha256 of question + rubric criteria used
            $table->decimal('suggested_marks', 8, 2)->nullable();
            $table->decimal('maximum_marks', 8, 2);
            $table->text('overall_feedback')->nullable();
            $table->json('strengths')->nullable();
            $table->json('missing_elements')->nullable();
            $table->text('evaluation_summary')->nullable();
            $table->string('grading_status', 20)->default('PENDING'); // PENDING, PROCESSING, COMPLETED, FAILED, REVIEWED, FINALIZED
            $table->boolean('is_current')->default(true);
            $table->string('faculty_decision', 20)->nullable(); // ACCEPTED, MODIFIED, REJECTED
            $table->string('error_message', 500)->nullable();
            $table->string('model_name')->nullable();
            $table->string('model_version', 50)->nullable();
            $table->string('generation_method', 40)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['student_answer_id', 'is_current']);
            $table->index(['student_answer_id', 'grading_status']);
            $table->index('student_submission_id');
            $table->index('question_id');
            $table->index('rubric_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_grading_results');
    }
};
