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
        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->integer('question_number');
            $table->text('question_text');
            $table->string('question_type')->default('descriptive'); // mcq, short_answer, descriptive, problem_solving, true_false, other
            $table->decimal('marks', 8, 2)->default(10.00);
            $table->string('difficulty_level')->default('medium'); // easy, medium, hard
            $table->string('cognitive_level')->default('Understand'); // Remember, Understand, Apply, Analyze, Evaluate, Create
            $table->foreignId('learning_outcome_id')->nullable()->constrained('learning_outcomes')->nullOnDelete();
            $table->text('expected_answer')->nullable();
            $table->timestamps();

            $table->index(['assessment_id', 'question_number']);
            $table->index('learning_outcome_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};

