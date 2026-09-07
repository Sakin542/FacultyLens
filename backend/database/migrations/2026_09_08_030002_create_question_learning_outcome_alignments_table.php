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
        Schema::create('question_learning_outcome_alignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('analysis_report_id')->constrained('analysis_reports')->onDelete('cascade');
            $table->foreignId('question_id')->constrained('questions')->onDelete('cascade');
            $table->foreignId('learning_outcome_id')->constrained('learning_outcomes')->onDelete('cascade');
            $table->decimal('similarity_score', 5, 4)->default(0.0000); // 0.0000 to 1.0000
            $table->string('alignment', 50)->default('NOT_ALIGNED'); // STRONG_ALIGNMENT, WEAK_ALIGNMENT, NOT_ALIGNED
            $table->text('reasoning')->nullable();
            $table->timestamps();

            $table->unique(['analysis_report_id', 'question_id', 'learning_outcome_id'], 'qlo_report_q_lo_unique');
            $table->index(['analysis_report_id', 'alignment'], 'qlo_report_alignment_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('question_learning_outcome_alignments');
    }
};

