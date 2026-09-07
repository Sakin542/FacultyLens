<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('analysis_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->decimal('overall_score', 5, 2)->default(0.00);
            $table->decimal('topic_coverage_score', 5, 2)->default(0.00);
            $table->decimal('learning_outcome_alignment_score', 5, 2)->default(0.00);
            $table->decimal('difficulty_balance_score', 5, 2)->default(0.00);
            $table->decimal('cognitive_level_balance_score', 5, 2)->default(0.00);
            $table->decimal('similarity_score', 5, 2)->default(0.00);
            $table->integer('total_questions')->default(0);
            $table->integer('similar_questions_count')->default(0);
            $table->json('findings')->nullable();
            $table->string('analysis_status')->default('pending'); // pending, processing, completed, failed
            $table->timestamp('analyzed_at')->nullable();
            $table->timestamps();

            $table->index(['assessment_id', 'analysis_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('analysis_reports');
    }
};

