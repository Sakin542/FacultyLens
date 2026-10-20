<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** AI course design drafts (syllabus, lesson plans) and research paper summaries. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 16)->index(); // syllabus | lesson_plan
            $table->json('inputs')->nullable();
            $table->json('content');
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });

        Schema::create('paper_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 300)->nullable();
            $table->string('file_name', 255)->nullable();
            $table->unsignedInteger('word_count')->default(0);
            $table->json('summary');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paper_summaries');
        Schema::dropIfExists('course_plans');
    }
};
