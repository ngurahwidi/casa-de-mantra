<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            PropertyProfileSeeder::class,
            AdminUserSeeder::class,
            CancellationPolicySeeder::class,
            AmenitySeeder::class,
            NotificationTemplateSeeder::class,
            // Skips itself in production.
            DemoSeeder::class,
        ]);
    }
}
