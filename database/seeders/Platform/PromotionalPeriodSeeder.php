<?php

namespace Database\Seeders\Platform;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PromotionalPeriodSeeder extends Seeder
{
    public function run(): void
    {
        // Promotional periods are seeded inside the DB 12 migration.
        // This seeder is idempotent — skips if promos already exist.

        if (DB::connection('platform')->table('promotional_periods')->count() > 0) {
            $this->command->info('Promotional periods already seeded — skipping.');
            return;
        }

        DB::connection('platform')->table('promotional_periods')->insert([
            [
                'id'                 => \Illuminate\Support\Str::uuid(),
                'name'               => 'Founding Landowner',
                'promo_type'         => 'founding_landowner',
                'discount_type'      => 'percent',
                'discount_value'     => 100.00,
                'duration_months'    => 12,
                'max_redemptions'    => 500,
                'redemptions_used'   => 0,
                'start_date'         => now(),
                'end_date'           => now()->addYear(),
                'is_active'          => true,
                'created_at'         => now(),
                'updated_at'         => now(),
            ],
            [
                'id'                 => \Illuminate\Support\Str::uuid(),
                'name'               => 'Honeymoon Period',
                'promo_type'         => 'honeymoon',
                'discount_type'      => 'percent',
                'discount_value'     => 100.00,
                'duration_months'    => 3,
                'max_redemptions'    => null,
                'redemptions_used'   => 0,
                'start_date'         => now(),
                'end_date'           => null,
                'is_active'          => true,
                'created_at'         => now(),
                'updated_at'         => now(),
            ],
            [
                'id'                 => \Illuminate\Support\Str::uuid(),
                'name'               => 'Veteran Discount',
                'promo_type'         => 'veteran',
                'discount_type'      => 'percent',
                'discount_value'     => 20.00,
                'duration_months'    => null,
                'max_redemptions'    => null,
                'redemptions_used'   => 0,
                'start_date'         => now(),
                'end_date'           => null,
                'is_active'          => true,
                'created_at'         => now(),
                'updated_at'         => now(),
            ],
        ]);

        $this->command->info('Promotional periods seeded: 3 promos.');
    }
}
