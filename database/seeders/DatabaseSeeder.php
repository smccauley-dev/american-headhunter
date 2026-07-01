<?php

namespace Database\Seeders;

use Database\Seeders\Billing\TestSubscriptionSeeder;
use Database\Seeders\Communications\EmailTemplateSeeder;
use Database\Seeders\Identity\PermissionSeeder;
use Database\Seeders\Identity\RoleSeeder;
use Database\Seeders\Identity\TestUserSeeder;
use Database\Seeders\Wildlife\WildlifeReferenceSeeder;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // DB 1 — Identity
            RoleSeeder::class,
            PermissionSeeder::class,
            TestUserSeeder::class,

            // DB 12 — Platform (feature flags + promos)
            PlatformSeeder::class,

            // DB 2 — Property (sample listings for dev)
            PropertySeeder::class,

            // DB 4 — Billing (dev test subscriptions; needs platform plans + test users)
            TestSubscriptionSeeder::class,

            // DB 7 — Communications (system email templates)
            EmailTemplateSeeder::class,

            // DB 5 — Wildlife (hunting seasons + CWD zone reference data)
            WildlifeReferenceSeeder::class,
        ]);
    }
}
