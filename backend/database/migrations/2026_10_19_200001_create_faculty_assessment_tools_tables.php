<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faculty assessment tools: exam paper builder (+ exam sets with question variants), plagiarism check across
 * student answers, and a reusable feedback comment bank.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_papers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('blueprint_id')->nullable()->constrained('assessment_blueprints')->nullOnDelete();
            $table->foreignId('parent_paper_id')->nullable()->constrained('exam_papers')->cascadeOnDelete();
            $table->string('title', 255);
            $table->string('set_label', 8)->default('A');
            $table->text('instructions')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->decimal('total_marks', 8, 2)->default(0);
            $table->string('status', 16)->default('draft')->index(); // draft | final
            $table->string('build_mode', 16)->default('manual');     // blueprint | targets | manual | set
            $table->json('targets')->nullable();
            $table->json('coverage')->nullable();
            $table->json('shortfalls')->nullable();
            $table->unsignedInteger('seed')->default(1);
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });

        Schema::create('exam_paper_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_paper_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('section_title', 255)->nullable();
            $table->string('source_type', 16)->default('manual'); // bank | question | generated | manual
            $table->unsignedBigInteger('source_id')->nullable();
            $table->text('question_text');
            $table->text('expected_answer')->nullable();
            $table->string('question_type', 32)->nullable();
            $table->decimal('marks', 6, 2)->default(0);
            $table->string('difficulty_level', 16)->nullable();
            $table->string('cognitive_level', 16)->nullable();
            $table->string('topic', 255)->nullable();
            $table->foreignId('learning_outcome_id')->nullable()->constrained()->nullOnDelete();
            $table->json('options')->nullable();
            $table->foreignId('variant_of_item_id')->nullable()->constrained('exam_paper_items')->nullOnDelete();
            $table->json('variant_meta')->nullable();
            $table->boolean('answer_needs_review')->default(false);
            $table->timestamps();
            $table->index(['exam_paper_id', 'sort_order']);
        });

        Schema::create('answer_similarity_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('run_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->default('completed');
            $table->string('method', 32);
            $table->decimal('threshold', 4, 3);
            $table->unsignedInteger('answers_compared')->default(0);
            $table->unsignedInteger('pairs_compared')->default(0);
            $table->unsignedInteger('pairs_flagged')->default(0);
            $table->json('summary')->nullable();
            $table->timestamps();
        });

        Schema::create('answer_similarity_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('answer_similarity_runs')->cascadeOnDelete();
            $table->foreignId('question_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('answer_a_id')->constrained('student_answers')->cascadeOnDelete();
            $table->foreignId('answer_b_id')->constrained('student_answers')->cascadeOnDelete();
            $table->decimal('lexical_score', 5, 4);
            $table->decimal('semantic_score', 5, 4)->nullable();
            $table->decimal('combined_score', 5, 4)->index();
            $table->text('shared_excerpt')->nullable();
            $table->string('review_status', 16)->default('pending'); // pending | confirmed | dismissed
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('feedback_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $table->string('criterion', 255)->nullable();
            $table->string('title', 120);
            $table->text('body');
            $table->string('tone', 16)->default('neutral'); // positive | improvement | neutral
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'usage_count']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_comments');
        Schema::dropIfExists('answer_similarity_matches');
        Schema::dropIfExists('answer_similarity_runs');
        Schema::dropIfExists('exam_paper_items');
        Schema::dropIfExists('exam_papers');
    }
};
