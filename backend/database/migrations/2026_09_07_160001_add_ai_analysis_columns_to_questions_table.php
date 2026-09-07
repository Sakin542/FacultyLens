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
        Schema::table('questions', function (Blueprint $table) {
            $table->string('ai_question_type')->nullable()->after('expected_answer');
            $table->string('ai_difficulty_level')->nullable()->after('ai_question_type');
            $table->string('ai_cognitive_level')->nullable()->after('ai_difficulty_level');
            $table->json('ai_topics')->nullable()->after('ai_cognitive_level');
            $table->string('ai_analysis_status')->default('pending')->after('ai_topics'); // pending, processing, completed, failed
            $table->timestamp('ai_analyzed_at')->nullable()->after('ai_analysis_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropColumn([
                'ai_question_type',
                'ai_difficulty_level',
                'ai_cognitive_level',
                'ai_topics',
                'ai_analysis_status',
                'ai_analyzed_at',
            ]);
        });
    }
};

