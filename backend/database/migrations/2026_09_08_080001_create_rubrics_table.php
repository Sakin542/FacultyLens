<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * STEP 25: AI-generated draft rubrics for individual assessment questions.
     * Each generation creates a new version; previous versions are never overwritten.
     */
    public function up(): void
    {
        Schema::create('rubrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->decimal('total_marks', 8, 2);
            $table->string('status', 20)->default('DRAFT'); // DRAFT, APPROVED, ARCHIVED
            $table->unsignedInteger('version')->default(1);
            $table->string('generation_method', 40)->default('ai_assisted'); // ai_assisted, template_based, manual
            $table->string('ai_model')->nullable();
            $table->string('ai_model_version', 50)->nullable();
            $table->text('general_guidance')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['question_id', 'version']);
            $table->index(['question_id', 'status']);
            $table->index('assessment_id');
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rubrics');
    }
};
