<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Production-safe: seeds no users, measurements, or demo data. Create the
 * first owner with `php artisan noise:create-owner`; local synthetic data
 * comes from `noise:demo:provision` + `noise:simulate`, which label it as
 * synthetic and refuse to run in production.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->info('Nothing to seed. Use `php artisan noise:create-owner <email>` to create the initial owner.');
    }
}
