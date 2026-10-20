<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Entry point for `php artisan db:seed`.
 *
 * Production receives NO synthetic academic data: development/test fixtures live in DevelopmentSeeder
 * and are refused when APP_ENV=production unless ALLOW_DEV_SEED=true is set explicitly.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // The administrator account (ADMIN_EMAIL / ADMIN_PASSWORD) is set up in every environment that configures it
        if (config('admin.email') !== '' && (string) config('admin.password') !== '') {
            \Illuminate\Support\Facades\Artisan::call('admin:ensure');
            $this->command?->info(trim(\Illuminate\Support\Facades\Artisan::output()));
        }

        if (app()->environment('production') && ! filter_var(env('ALLOW_DEV_SEED', false), FILTER_VALIDATE_BOOL)) {
            $this->command?->warn('Skipping development fixtures: APP_ENV=production. Production is set up through registration, not seed data.');

            return;
        }

        $this->call(DevelopmentSeeder::class);
        $this->call(FacultyToolsDemoSeeder::class);
    }
}
