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
        Schema::create('recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('analysis_report_id')->constrained()->cascadeOnDelete();
            $table->string('category')->default('general'); // topic_coverage, learning_outcome, difficulty, cognitive_level, similarity, assessment_quality, general
            $table->string('title');
            $table->text('description');
            $table->string('priority')->default('medium'); // low, medium, high
            $table->string('status')->default('pending'); // pending, reviewed, accepted, dismissed
            $table->timestamps();

            $table->index(['analysis_report_id', 'priority']);
            $table->index(['analysis_report_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recommendations');
    }
};

