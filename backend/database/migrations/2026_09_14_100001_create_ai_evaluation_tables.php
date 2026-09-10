<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STEP 35: AI Evaluation & Model Performance — model registry, prompt versions, evaluation datasets/examples,
 * runs, aggregate metrics, example-level predictions and human ratings. Separate from production AI tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_models', function (Blueprint $table) {
            $table->id();
            $table->string('model_name');
            $table->string('provider', 60)->default('Hugging Face');
            $table->string('model_type', 40); // embedding | generation | rule_engine | template_engine
            $table->string('task', 60);
            $table->string('version', 60)->default('configured');
            $table->json('configuration')->nullable(); // never secrets
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['model_name', 'task', 'version'], 'ai_models_name_task_version_unique');
        });

        Schema::create('ai_prompt_versions', function (Blueprint $table) {
            $table->id();
            $table->string('feature', 60);
            $table->string('version', 40);
            $table->string('prompt_hash', 64)->nullable();
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['feature', 'version'], 'ai_prompt_versions_feature_version_unique');
        });

        Schema::create('ai_evaluation_datasets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('task', 40);
            $table->string('version', 40)->default('v1');
            $table->string('source', 40)->default('FACULTY_VALIDATED');
            $table->string('split', 20)->default('TEST');
            $table->string('status', 20)->default('DRAFT'); // DRAFT | READY | RUNNING | COMPLETED | ARCHIVED
            $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->json('validation_report')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamps();
            $table->index(['created_by', 'task'], 'ai_eval_datasets_owner_task_idx');
            $table->index('status', 'ai_eval_datasets_status_idx');
        });

        Schema::create('ai_evaluation_examples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dataset_id')->constrained('ai_evaluation_datasets')->cascadeOnDelete();
            $table->json('input_data');
            $table->json('expected_output');
            $table->json('metadata')->nullable();
            $table->string('source', 40)->default('FACULTY_VALIDATED');
            $table->string('split', 20)->default('TEST');
            $table->string('fingerprint', 64); // sha256(input) for duplicate detection
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['dataset_id', 'fingerprint'], 'ai_eval_examples_dataset_fp_idx');
        });

        Schema::create('ai_evaluation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dataset_id')->constrained('ai_evaluation_datasets')->cascadeOnDelete();
            $table->string('task', 40);
            $table->foreignId('model_id')->nullable()->constrained('ai_models')->nullOnDelete();
            $table->foreignId('prompt_version_id')->nullable()->constrained('ai_prompt_versions')->nullOnDelete();
            $table->json('configuration')->nullable();
            $table->string('status', 20)->default('PENDING'); // PENDING | RUNNING | COMPLETED | FAILED | CANCELLED
            $table->string('gate_status', 30)->nullable(); // PASSED | PASSED_WITH_WARNINGS | FAILED
            $table->json('summary')->nullable(); // headline metrics, size category, gate results, regression, limitations
            $table->unsignedInteger('example_count')->default(0);
            $table->unsignedInteger('processed_count')->default(0);
            $table->unsignedInteger('inference_ms')->default(0);
            $table->text('failure_reason')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->index(['task', 'status', 'completed_at'], 'ai_eval_runs_task_status_idx');
            $table->index(['dataset_id', 'created_at'], 'ai_eval_runs_dataset_created_idx');
        });

        Schema::create('ai_evaluation_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluation_run_id')->constrained('ai_evaluation_runs')->cascadeOnDelete();
            $table->string('metric_name', 80);
            $table->decimal('metric_value', 12, 6)->nullable();
            $table->json('metric_metadata')->nullable();
            $table->timestamps();
            $table->unique(['evaluation_run_id', 'metric_name'], 'ai_eval_results_run_metric_unique');
        });

        Schema::create('ai_evaluation_predictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluation_run_id')->constrained('ai_evaluation_runs')->cascadeOnDelete();
            $table->foreignId('example_id')->constrained('ai_evaluation_examples')->cascadeOnDelete();
            $table->json('prediction')->nullable();
            $table->json('expected_output')->nullable();
            $table->boolean('is_correct')->nullable();
            $table->decimal('score', 10, 4)->nullable();
            $table->string('error_type', 40)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['evaluation_run_id', 'example_id'], 'ai_eval_predictions_run_example_unique'); // idempotency
            $table->index(['evaluation_run_id', 'error_type'], 'ai_eval_predictions_run_error_idx');
        });

        Schema::create('ai_evaluation_ratings', function (Blueprint $table) {
            $table->id();
            $table->string('rateable_type', 40); // rubric | generated_question | evaluation_prediction
            $table->unsignedBigInteger('rateable_id');
            $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->json('dimension_scores'); // {dimension: 1-5}
            $table->decimal('overall_score', 4, 2)->nullable();
            $table->string('decision', 20)->nullable(); // ACCEPTED | REVISED | REJECTED
            $table->text('comment')->nullable();
            $table->timestamps();
            $table->unique(['rateable_type', 'rateable_id', 'user_id'], 'ai_eval_ratings_target_user_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_evaluation_ratings');
        Schema::dropIfExists('ai_evaluation_predictions');
        Schema::dropIfExists('ai_evaluation_results');
        Schema::dropIfExists('ai_evaluation_runs');
        Schema::dropIfExists('ai_evaluation_examples');
        Schema::dropIfExists('ai_evaluation_datasets');
        Schema::dropIfExists('ai_prompt_versions');
        Schema::dropIfExists('ai_models');
    }
};
