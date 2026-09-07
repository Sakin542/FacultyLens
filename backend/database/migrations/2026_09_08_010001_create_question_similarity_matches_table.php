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
        Schema::create('question_similarity_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('analysis_report_id')->constrained('analysis_reports')->onDelete('cascade');
            $table->foreignId('current_question_id')->nullable()->constrained('questions')->onDelete('cascade');
            $table->foreignId('previous_question_id')->nullable()->constrained('previous_questions')->onDelete('cascade');
            $table->decimal('similarity_score', 5, 4); // 0.0000 to 1.0000
            $table->string('similarity_status', 50)->default('NOT_SIMILAR'); // POTENTIAL_DUPLICATE, HIGHLY_SIMILAR, etc.
            $table->text('reasoning')->nullable();
            $table->timestamps();

            $table->index(['analysis_report_id', 'similarity_status'], 'qsm_report_status_idx');
            $table->index('similarity_score', 'qsm_score_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('question_similarity_matches');
    }
};
