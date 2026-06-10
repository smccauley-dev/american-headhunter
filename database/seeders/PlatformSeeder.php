<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class PlatformSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            \Database\Seeders\Platform\FeatureFlagSeeder::class,
            \Database\Seeders\Platform\PromotionalPeriodSeeder::class,
            \Database\Seeders\Platform\HomepageSettingsSeeder::class,
            \Database\Seeders\Platform\NavSettingsSeeder::class,
        ]);
    }
}
