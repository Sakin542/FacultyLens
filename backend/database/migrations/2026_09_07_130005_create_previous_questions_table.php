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
        Schema::create('previous_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->text('question_text');
            $table->string('question_type')->default('descriptive');
            $table->decimal('marks', 8, 2)->nullable();
            $table->string('difficulty_level')->nullable();
            $table->string('cognitive_level')->nullable();
            $table->string('source')->default('previous_exam'); // previous_exam, question_bank, uploaded_document, manual, other
            $table->string('source_year')->nullable();
            $table->string('source_assessment')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'course_id']);
            $table->index(['course_id', 'source']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('previous_questions');
    }
};

