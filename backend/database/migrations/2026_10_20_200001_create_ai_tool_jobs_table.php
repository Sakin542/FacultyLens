<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI toolkit jobs (handwriting OCR, lecture transcription). The uploaded file lives in private storage only until
 * the job finishes; afterwards only the text result is kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_tool_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('tool', 24);                 // ocr | transcription
            $table->string('status', 16)->index();      // queued | processing | completed | failed
            $table->string('input_filename', 191);
            $table->string('input_path', 255)->nullable();
            $table->unsignedBigInteger('input_bytes')->default(0);
            $table->string('language', 8)->nullable();
            $table->longText('result_text')->nullable();
            $table->json('result')->nullable();         // segments/pages, model, provider, duration (no file paths)
            $table->string('error', 255)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_tool_jobs');
    }
};
