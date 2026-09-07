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
        Schema::create('document_processings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assessment_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('document_type')->default('other'); // syllabus, question_paper, assignment, previous_exam, other
            $table->string('original_file_name');
            $table->string('stored_file_name');
            $table->string('file_path');
            $table->string('mime_type');
            $table->unsignedBigInteger('file_size'); // bytes
            $table->longText('extracted_text')->nullable();
            $table->longText('cleaned_text')->nullable();
            $table->string('processing_status')->default('uploaded'); // uploaded, processing, completed, failed
            $table->text('processing_error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'course_id']);
            $table->index(['course_id', 'assessment_id']);
            $table->index('processing_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_processings');
    }
};

