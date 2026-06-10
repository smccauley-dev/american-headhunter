<?php

namespace Database\Seeders\Platform;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FeatureFlagSeeder extends Seeder
{
    public function run(): void
    {
        // Feature flags are seeded inside the DB 12 migration via INSERT INTO statements.
        // This seeder exists for standalone re-seeding without rolling back migrations.
        // Use upsert to be idempotent.

        $flags = [
            ['key' => 'auction_module',              'display_name' => 'Auction Module',              'is_enabled' => false, 'rollout_percentage' => 0],
            ['key' => 'consulting_marketplace',      'display_name' => 'Consulting Marketplace',      'is_enabled' => false, 'rollout_percentage' => 0],
            ['key' => 'outfitter_booking',           'display_name' => 'Outfitter Booking',           'is_enabled' => false, 'rollout_percentage' => 0],
            ['key' => 'equipment_marketplace',       'display_name' => 'Equipment Marketplace',       'is_enabled' => false, 'rollout_percentage' => 0],
            ['key' => 'club_leases',                 'display_name' => 'Club Leases',                 'is_enabled' => true,  'rollout_percentage' => 100],
            ['key' => 'carbon_credits',              'display_name' => 'Carbon Credits',              'is_enabled' => false, 'rollout_percentage' => 0],
            ['key' => 'smart_lock_iot',              'display_name' => 'Smart Lock IoT',              'is_enabled' => false, 'rollout_percentage' => 0],
            ['key' => 'bundled_insurance',           'display_name' => 'Bundled Insurance',           'is_enabled' => false, 'rollout_percentage' => 0],
            ['key' => 'ai_trophy_scoring',           'display_name' => 'AI Trophy Scoring',           'is_enabled' => false, 'rollout_percentage' => 0],
            ['key' => 'public_api',                  'display_name' => 'Public API',                  'is_enabled' => false, 'rollout_percentage' => 0],
            ['key' => 'data_monetization',           'display_name' => 'Data Monetization',           'is_enabled' => false, 'rollout_percentage' => 0],
            ['key' => 'digital_id_cards',            'display_name' => 'Digital ID Cards',            'is_enabled' => false, 'rollout_percentage' => 0],
            ['key' => 'veteran_discounts',           'display_name' => 'Veteran Discounts',           'is_enabled' => true,  'rollout_percentage' => 100],
            ['key' => 'youth_programs',              'display_name' => 'Youth Programs',              'is_enabled' => true,  'rollout_percentage' => 100],
            ['key' => 'offline_pwa',                 'display_name' => 'Offline PWA',                 'is_enabled' => false, 'rollout_percentage' => 0],
            ['key' => 'saml_sso',                    'display_name' => 'SAML SSO',                    'is_enabled' => false, 'rollout_percentage' => 0],
            ['key' => 'two_person_authorization',    'display_name' => 'Two-Person Authorization',    'is_enabled' => false, 'rollout_percentage' => 0],
            ['key' => 'lease_wanted_board',          'display_name' => 'Lease Wanted Board',          'is_enabled' => false, 'rollout_percentage' => 0],
            ['key' => 'population_modeling',         'display_name' => 'Population Modeling',         'is_enabled' => false, 'rollout_percentage' => 0],
            ['key' => 'wildlife_photography_tourism','display_name' => 'Wildlife Photography Tourism', 'is_enabled' => false, 'rollout_percentage' => 0],
            ['key' => 'club_expense_sharing',        'display_name' => 'Club Expense Sharing',        'is_enabled' => false, 'rollout_percentage' => 0],
        ];

        foreach ($flags as $flag) {
            DB::connection('platform')->table('feature_flags')->updateOrInsert(
                ['key' => $flag['key']],
                [
                    'display_name'        => $flag['display_name'],
                    'is_enabled'          => $flag['is_enabled'],
                    'rollout_percentage'  => $flag['rollout_percentage'],
                    'updated_at'          => now(),
                ]
            );
        }

        $this->command->info('Feature flags seeded: ' . count($flags) . ' flags.');
    }
}
