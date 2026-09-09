<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STEP 28: Criterion-level alignment (one row per rubric criterion). Evidence is answer excerpts only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('answer_rubric_criterion_alignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('answer_rubric_alignment_id')->constrained('answer_rubric_alignments', 'id', 'arca_alignment_fk')->cascadeOnDelete();
            $table->foreignId('rubric_criterion_id')->nullable()->constrained('rubric_criteria', 'id', 'arca_criterion_fk')->nullOnDelete();
            $table->string('criterion');
            $table->decimal('max_marks', 8, 2);
            $table->decimal('alignment_score', 4, 2);        // status weight: 1 / .5 / .25 / 0
            $table->decimal('similarity', 5, 4)->nullable();  // combined semantic/lexical signal
            $table->string('alignment_status', 20);
            $table->json('evidence')->nullable();
            $table->json('missing_elements')->nullable();
            $table->text('explanation');
            $table->unsignedInteger('sort_order')->default(1);
            $table->timestamps();

            $table->index(['answer_rubric_alignment_id', 'sort_order'], 'arca_alignment_order_idx');
            $table->index('rubric_criterion_id', 'arca_criterion_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('answer_rubric_criterion_alignments');
    }
};
