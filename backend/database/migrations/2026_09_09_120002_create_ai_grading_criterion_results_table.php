<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STEP 27: Criterion-level AI grading suggestions (one row per rubric criterion).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_grading_criterion_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_grading_result_id')->constrained('ai_grading_results')->cascadeOnDelete();
            $table->foreignId('rubric_criterion_id')->nullable()->constrained('rubric_criteria')->nullOnDelete();
            $table->string('criterion'); // snapshot of the criterion title at grading time
            $table->decimal('suggested_marks', 8, 2);
            $table->decimal('maximum_marks', 8, 2);
            $table->text('evaluation');
            $table->json('evidence')->nullable();
            $table->json('missing_elements')->nullable();
            $table->string('coverage_level', 20)->nullable();
            $table->unsignedInteger('sort_order')->default(1);
            $table->timestamps();

            // Explicit names: MySQL caps identifiers at 64 chars.
            $table->index(['ai_grading_result_id', 'sort_order'], 'ai_grading_crit_results_result_sort_idx');
            $table->index('rubric_criterion_id', 'ai_grading_crit_results_criterion_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_grading_criterion_results');
    }
};
