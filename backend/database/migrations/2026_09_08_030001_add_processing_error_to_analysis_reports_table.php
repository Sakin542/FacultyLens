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
            $table->text('processing_error')->nullable()->after('analysis_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('analysis_reports', function (Blueprint $table) {
            $table->dropColumn('processing_error');
        });
    }
};

