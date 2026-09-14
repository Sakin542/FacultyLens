<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * email_enabled becomes tri-state: NULL = the user never chose (category default from config/email.php applies),
 * true/false = explicit choice. Rows written before the e-mail system existed never carried a user decision, so
 * they are reset to NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table) {
            $table->boolean('email_enabled')->nullable()->default(null)->change();
        });
        DB::table('notification_preferences')->update(['email_enabled' => null]);
    }

    public function down(): void
    {
        DB::table('notification_preferences')->whereNull('email_enabled')->update(['email_enabled' => false]);
        Schema::table('notification_preferences', function (Blueprint $table) {
            $table->boolean('email_enabled')->nullable(false)->default(false)->change();
        });
    }
};
