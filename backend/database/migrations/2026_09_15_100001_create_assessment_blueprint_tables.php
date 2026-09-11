<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STEP 37: Assessment Blueprint — versioned planning structure linked to an existing assessment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_blueprints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained('assessments')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 20)->default('DRAFT'); // DRAFT | VALIDATED | FINALIZED | ARCHIVED
            $table->boolean('is_current')->default(true);
            $table->string('title', 200)->nullable();
            $table->decimal('total_marks', 8, 2);
            $table->unsignedInteger('total_questions');
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->text('instructions')->nullable();
            $table->string('validation_status', 30)->nullable(); // VALID | VALID_WITH_WARNINGS | INVALID
            $table->json('validation_result')->nullable();
            $table->decimal('blueprint_completeness', 5, 2)->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['assessment_id', 'version'], 'assessment_blueprints_assessment_version_unique');
            $table->index(['assessment_id', 'is_current'], 'assessment_blueprints_current_idx');
        });

        Schema::create('assessment_blueprint_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blueprint_id')->constrained('assessment_blueprints')->cascadeOnDelete();
            $table->string('title', 150);
            $table->unsignedInteger('section_order')->default(1);
            $table->text('instructions')->nullable();
            $table->string('question_type', 40)->nullable();
            $table->unsignedInteger('question_count');
            $table->decimal('marks_per_question', 8, 2);
            $table->decimal('total_marks', 8, 2);
            $table->json('difficulty_distribution')->nullable();
            $table->json('cognitive_distribution')->nullable();
            $table->timestamps();
            $table->index(['blueprint_id', 'section_order'], 'blueprint_sections_order_idx');
        });

        Schema::create('assessment_blueprint_constraints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blueprint_id')->constrained('assessment_blueprints')->cascadeOnDelete();
            $table->string('dimension', 30); // DIFFICULTY | COGNITIVE_LEVEL | LEARNING_OUTCOME | PROGRAM_OUTCOME | TOPIC | QUESTION_TYPE
            $table->string('target_type', 20)->default('percentage'); // percentage | count | marks
            $table->string('target_key', 150); // easy / Apply / lo:{id} / po:{id} / topic text / question type
            $table->foreignId('learning_outcome_id')->nullable()->constrained('learning_outcomes')->cascadeOnDelete();
            $table->foreignId('program_outcome_id')->nullable()->constrained('program_outcomes')->cascadeOnDelete();
            $table->decimal('target_percentage', 6, 2)->nullable();
            $table->unsignedInteger('target_count')->nullable();
            $table->decimal('target_marks', 8, 2)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['blueprint_id', 'dimension', 'target_key'], 'blueprint_constraints_unique');
        });

        Schema::create('assessment_blueprint_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blueprint_id')->constrained('assessment_blueprints')->cascadeOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('assessment_blueprint_sections')->nullOnDelete();
            $table->string('topic', 255)->nullable();
            $table->foreignId('learning_outcome_id')->nullable()->constrained('learning_outcomes')->nullOnDelete();
            $table->foreignId('program_outcome_id')->nullable()->constrained('program_outcomes')->nullOnDelete();
            $table->string('question_type', 40)->nullable();
            $table->string('difficulty_level', 20)->nullable();
            $table->string('cognitive_level', 20)->nullable();
            $table->unsignedInteger('question_count');
            $table->decimal('marks_each', 8, 2);
            $table->decimal('total_marks', 8, 2);
            $table->unsignedInteger('sort_order')->default(1);
            $table->timestamps();
            $table->index(['blueprint_id', 'sort_order'], 'blueprint_items_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_blueprint_items');
        Schema::dropIfExists('assessment_blueprint_constraints');
        Schema::dropIfExists('assessment_blueprint_sections');
        Schema::dropIfExists('assessment_blueprints');
    }
};
