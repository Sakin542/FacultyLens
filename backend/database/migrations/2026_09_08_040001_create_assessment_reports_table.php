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
        Schema::create('assessment_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('analysis_report_id')->constrained()->cascadeOnDelete();
            $table->uuid('report_uuid')->unique();
            $table->string('file_name');
            $table->string('file_path');
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('generation_status')->default('pending'); // pending, generating, completed, failed
            $table->boolean('is_shareable')->default(false);
            $table->string('share_token', 64)->nullable()->unique();
            $table->timestamp('shared_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['assessment_id', 'generation_status']);
            $table->index('share_token');
            $table->index('report_uuid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assessment_reports');
    }
};

