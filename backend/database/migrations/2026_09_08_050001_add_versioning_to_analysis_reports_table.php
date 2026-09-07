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
        Schema::table('analysis_reports', function (Blueprint $table) {
            $table->unsignedInteger('analysis_version')->default(1)->after('assessment_id');
            $table->boolean('is_current')->default(true)->after('analysis_status');

            $table->index(['assessment_id', 'analysis_version']);
            $table->index(['assessment_id', 'is_current']);
            $table->index(['assessment_id', 'analyzed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('analysis_reports', function (Blueprint $table) {
            $table->dropIndex(['assessment_id', 'analysis_version']);
            $table->dropIndex(['assessment_id', 'is_current']);
            $table->dropIndex(['assessment_id', 'analyzed_at']);

            $table->dropColumn(['analysis_version', 'is_current']);
        });
    }
};

