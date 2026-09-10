<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STEP 33: Constrained Question Generator — generation requests and reviewable draft questions.
 * Drafts live in generated_questions and are only copied into `questions` after faculty approval.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_generation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assessment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('topic', 255)->nullable();
            $table->foreignId('learning_outcome_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('program_outcome_id')->nullable()->constrained()->nullOnDelete();
            $table->string('question_type', 30);
            $table->string('difficulty_level', 20)->nullable();
            $table->string('cognitive_level', 20)->nullable();
            $table->decimal('marks', 8, 2)->nullable();
            $table->unsignedSmallInteger('number_of_questions')->default(1);
            $table->string('language', 40)->default('English');
            $table->json('document_scope')->nullable();
            $table->json('blueprint')->nullable();
            $table->boolean('include_expected_answer')->default(true);
            $table->boolean('include_explanation')->default(false);
            $table->string('generation_status', 20)->default('PENDING');
            $table->string('generation_method', 40)->nullable();
            $table->string('generation_model')->nullable();
            $table->string('generation_model_version')->nullable();
            $table->string('embedding_model')->nullable();
            $table->string('prompt_version', 20)->nullable();
            $table->unsignedTinyInteger('regeneration_count')->default(0);
            $table->json('feedback')->nullable();
            $table->json('warnings')->nullable();
            $table->json('set_summary')->nullable();
            $table->json('blueprint_summary')->nullable();
            $table->unsignedSmallInteger('retrieved_chunks')->default(0);
            $table->unsignedSmallInteger('existing_questions_count')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at'], 'qgen_requests_user_created_idx');
            $table->index(['course_id', 'generation_status'], 'qgen_requests_course_status_idx');
            $table->index('assessment_id', 'qgen_requests_assessment_idx');
        });

        Schema::create('generated_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('generation_request_id')->constrained('question_generation_requests')->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence')->default(1);
            $table->text('question_text');
            $table->text('original_question_text');
            $table->string('question_type', 30);
            $table->decimal('marks', 8, 2);
            $table->string('difficulty_level', 20)->nullable();
            $table->string('cognitive_level', 20)->nullable();
            $table->foreignId('learning_outcome_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('program_outcome_id')->nullable()->constrained()->nullOnDelete();
            $table->string('topic', 255)->nullable();
            $table->json('options')->nullable();
            $table->string('correct_option', 500)->nullable();
            $table->text('expected_answer')->nullable();
            $table->text('explanation')->nullable();
            $table->json('source_chunk_ids')->nullable();
            $table->json('validation')->nullable();
            $table->string('validation_status', 30)->default('PENDING');
            $table->string('review_status', 20)->default('DRAFT');
            $table->text('review_note')->nullable();
            $table->unsignedSmallInteger('version')->default(1);
            $table->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('edited_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('regenerated_from_id')->nullable()->constrained('generated_questions')->nullOnDelete();
            $table->foreignId('official_question_id')->nullable()->constrained('questions')->nullOnDelete();
            $table->timestamp('added_to_assessment_at')->nullable();
            $table->timestamps();

            $table->index(['generation_request_id', 'sequence'], 'gen_questions_request_seq_idx');
            $table->index('learning_outcome_id', 'gen_questions_lo_idx');
            $table->index('validation_status', 'gen_questions_validation_idx');
            $table->index('review_status', 'gen_questions_review_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generated_questions');
        Schema::dropIfExists('question_generation_requests');
    }
};
