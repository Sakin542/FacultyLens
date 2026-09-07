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
        Schema::create('ai_improvement_signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('recommendation_id')->constrained('recommendations')->onDelete('cascade');
            $table->foreignId('analysis_report_id')->constrained('analysis_reports')->onDelete('cascade');
            $table->foreignId('assessment_id')->constrained('assessments')->onDelete('cascade');
            $table->string('signal_type', 100);
            $table->string('signal_value', 20); // positive, negative, neutral
            $table->string('source', 50)->default('faculty_feedback');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'signal_type']);
            $table->index(['assessment_id']);
            $table->index(['recommendation_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_improvement_signals');
    }
};

