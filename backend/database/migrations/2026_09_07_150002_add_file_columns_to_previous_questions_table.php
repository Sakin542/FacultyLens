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
        Schema::table('previous_questions', function (Blueprint $table) {
            $table->string('file_name')->nullable()->after('source_assessment');
            $table->string('file_path')->nullable()->after('file_name');
            $table->string('file_type', 20)->nullable()->after('file_path');
            $table->unsignedBigInteger('file_size')->nullable()->after('file_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('previous_questions', function (Blueprint $table) {
            $table->dropColumn(['file_name', 'file_path', 'file_type', 'file_size']);
        });
    }
};

