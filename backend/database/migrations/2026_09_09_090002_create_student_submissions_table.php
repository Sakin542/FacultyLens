<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * STEP 26: One submission per student per assessment.
     * grading_status is prepared for later AI-assisted grading steps; STEP 26 only uses manual states.
     */
    public function up(): void
    {
        Schema::create('student_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('submission_identifier', 64)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->string('status', 20)->default('SUBMITTED'); // DRAFT, SUBMITTED, UNDER_REVIEW, GRADED, RETURNED
            $table->string('grading_status', 20)->default('NOT_STARTED'); // NOT_STARTED, IN_PROGRESS, AI_ASSISTED, FACULTY_REVIEWED, FINALIZED
            $table->decimal('total_marks', 8, 2)->nullable();
            $table->decimal('awarded_marks', 8, 2)->nullable();
            $table->timestamps();

            $table->unique(['assessment_id', 'student_id']);
            $table->index('assessment_id');
            $table->index('student_id');
            $table->index('status');
            $table->index('grading_status');
            $table->index('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_submissions');
    }
};
