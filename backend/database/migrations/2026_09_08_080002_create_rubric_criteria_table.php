<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * STEP 25: Criteria belonging to a rubric. Deleted together with their rubric.
     */
    public function up(): void
    {
        Schema::create('rubric_criteria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rubric_id')->constrained()->cascadeOnDelete();
            $table->string('criterion');
            $table->text('description');
            $table->decimal('max_marks', 8, 2);
            $table->text('scoring_guidance')->nullable();
            $table->json('expected_indicators')->nullable();
            $table->unsignedInteger('sort_order')->default(1);
            $table->timestamps();

            $table->index(['rubric_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rubric_criteria');
    }
};
