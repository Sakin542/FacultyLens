<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STEP 45: faculty review / override records for AI results, and relaxed FKs on improvement signals so that
 * override feedback for non-recommendation results can reuse the STEP 20 improvement-signal architecture.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_result_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('ai_result_type', 40);
            $table->unsignedBigInteger('ai_result_id');
            $table->string('action', 20); // ACCEPTED, REJECTED, REVIEWED, OVERRIDDEN
            $table->json('ai_value')->nullable();       // AI value at the time of review
            $table->json('override_value')->nullable(); // faculty value (OVERRIDDEN only)
            $table->string('override_reason', 60)->nullable();
            $table->text('comment')->nullable();
            $table->foreignId('analysis_report_id')->nullable()->constrained('analysis_reports')->nullOnDelete();
            $table->foreignId('course_id')->nullable()->constrained('courses')->nullOnDelete();
            $table->string('explanation_version', 20);
            $table->timestamps();

            $table->index(['ai_result_type', 'ai_result_id'], 'ai_result_reviews_type_id_idx');
            $table->index(['user_id', 'created_at'], 'ai_result_reviews_user_created_idx');
        });

        Schema::table('ai_improvement_signals', function (Blueprint $table) {
            $table->unsignedBigInteger('recommendation_id')->nullable()->change();
            $table->unsignedBigInteger('analysis_report_id')->nullable()->change();
            $table->unsignedBigInteger('assessment_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_result_reviews');
    }
};
