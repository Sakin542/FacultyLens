<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin panel + account security: deactivation, last sign-in, TOTP two-factor and Google sign-in identity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('role')->index();
            }
            if (!Schema::hasColumn('users', 'last_login_at')) {
                $table->timestamp('last_login_at')->nullable()->after('is_active');
            }
            if (!Schema::hasColumn('users', 'two_factor_secret')) {
                $table->text('two_factor_secret')->nullable()->after('password');
                $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
                $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
            }
            if (!Schema::hasColumn('users', 'google_id')) {
                $table->string('google_id', 64)->nullable()->unique()->after('email');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['google_id', 'two_factor_confirmed_at', 'two_factor_recovery_codes', 'two_factor_secret', 'last_login_at', 'is_active'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    if ($column === 'google_id') {
                        $table->dropUnique(['google_id']);
                    }
                    if ($column === 'is_active') {
                        $table->dropIndex(['is_active']);
                    }
                    $table->dropColumn($column);
                }
            }
        });
    }
};
