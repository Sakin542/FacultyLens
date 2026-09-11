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
        if (app()->environment('production') && ! filter_var(env('ALLOW_DEV_SEED', false), FILTER_VALIDATE_BOOL)) {
            $this->command?->warn('Skipping development fixtures: APP_ENV=production. Production is set up through registration, not seed data.');

            return;
        }

        $this->call(DevelopmentSeeder::class);
    }
}
