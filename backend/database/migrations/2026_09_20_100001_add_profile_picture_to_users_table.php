<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Profile pictures live on the private disk; the users row only stores the storage reference
     * (never the binary) plus a change timestamp used for cache-busting the avatar URL.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('profile_picture_path')->nullable()->after('designation');
            $table->timestamp('profile_picture_updated_at')->nullable()->after('profile_picture_path');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['profile_picture_path', 'profile_picture_updated_at']);
        });
    }
};
