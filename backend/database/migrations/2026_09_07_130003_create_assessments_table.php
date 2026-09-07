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
        Schema::create('assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('type')->default('midterm'); // quiz, midterm, final, assignment, class_test, project, other
            $table->text('description')->nullable();
            $table->date('assessment_date')->nullable();
            $table->decimal('total_marks', 8, 2)->default(100.00);
            $table->integer('duration_minutes')->nullable();
            $table->string('status')->default('draft'); // draft, published, completed
            $table->timestamps();

            $table->index(['course_id', 'status']);
            $table->index(['course_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assessments');
    }
};

