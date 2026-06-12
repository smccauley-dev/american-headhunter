<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // DB 1 — Identity
            \Database\Seeders\Identity\RoleSeeder::class,
            \Database\Seeders\Identity\PermissionSeeder::class,
            \Database\Seeders\Identity\TestUserSeeder::class,

            // DB 12 — Platform (feature flags + promos)
            PlatformSeeder::class,

            // DB 2 — Property (sample listings for dev)
            PropertySeeder::class,

            // DB 7 — Communications (system email templates)
            \Database\Seeders\Communications\EmailTemplateSeeder::class,
        ]);
    }
}
