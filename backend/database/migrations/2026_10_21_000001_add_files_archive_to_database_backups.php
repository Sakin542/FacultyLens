<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each backup can also carry a ZIP of the uploaded files (documents, question papers, materials, reports), stored
 * next to the database dump so one backup restores the whole application state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('database_backups', function (Blueprint $table) {
            $table->string('files_path', 255)->nullable()->after('path');
            $table->unsignedBigInteger('files_size_bytes')->default(0)->after('size_bytes');
            $table->unsignedInteger('files_count')->default(0)->after('files_size_bytes');
        });
    }

    public function down(): void
    {
        Schema::table('database_backups', function (Blueprint $table) {
            $table->dropColumn(['files_path', 'files_size_bytes', 'files_count']);
        });
    }
};
