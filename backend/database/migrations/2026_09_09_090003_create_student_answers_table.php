<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * STEP 26: A student's answer to one question within one submission.
     * original_answer_text preserves the imported evidence when faculty edit answer_text.
     */
    public function up(): void
    {
        Schema::create('student_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_submission_id')->constrained('student_submissions')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->string('answer_type', 20)->default('TEXT'); // TEXT, FILE, IMAGE, MCQ
            $table->text('answer_text')->nullable();
            $table->text('original_answer_text')->nullable();
            $table->boolean('is_faculty_edited')->default(false);
            $table->string('answer_file_path')->nullable();
            $table->string('answer_file_name')->nullable();
            $table->string('answer_file_type', 100)->nullable();
            $table->unsignedBigInteger('answer_file_size')->nullable();
            $table->decimal('awarded_marks', 8, 2)->nullable();
            $table->text('faculty_feedback')->nullable();
            $table->string('answer_status', 20)->default('NOT_REVIEWED'); // NOT_REVIEWED, UNDER_REVIEW, REVIEWED
            $table->timestamps();

            $table->unique(['student_submission_id', 'question_id']);
            $table->index('question_id');
            $table->index('answer_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_answers');
    }
};
