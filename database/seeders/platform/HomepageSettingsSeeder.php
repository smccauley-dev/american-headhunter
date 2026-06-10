<?php

namespace Database\Seeders\Platform;

use App\Models\Platform\TenantSettings;
use Illuminate\Database\Seeder;

class HomepageSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            // Top bar
            ['key' => 'topbar.tagline', 'value' => 'Hunting Lease Marketplace', 'description' => 'Top bar — left side tagline text'],
            ['key' => 'topbar.phone',   'value' => '(800) 555-0124',             'description' => 'Top bar — phone number displayed on left side'],
            ['key' => 'topbar.link1',   'value' => 'Hunters',                    'description' => 'Top bar — right link label 1 (leave blank to hide)'],
            ['key' => 'topbar.link2',   'value' => 'Landowners',                 'description' => 'Top bar — right link label 2 (leave blank to hide)'],
            ['key' => 'topbar.link3',   'value' => 'Clubs',                      'description' => 'Top bar — right link label 3 (leave blank to hide)'],
            ['key' => 'topbar.link4',   'value' => 'Outfitters',                 'description' => 'Top bar — right link label 4 (leave blank to hide)'],

            // Hero layout
            ['key' => 'home.hero_card_count', 'value' => '1', 'description' => 'Number of field cards shown in the hero (1 or 2)'],

            // Hero copy
            ['key' => 'home.hero_eyebrow',    'value' => 'The Premier Hunting Lease Marketplace', 'description' => 'Small label above the hero headline'],
            ['key' => 'home.hero_line1',       'value' => 'Land',                                  'description' => 'Hero headline — line 1'],
            ['key' => 'home.hero_line2',       'value' => 'worth &',                               'description' => 'Hero headline — line 2'],
            ['key' => 'home.hero_line3',       'value' => 'hunting.',                              'description' => 'Hero headline — line 3'],

            // Hero inline stats (3 items beneath the headline)
            ['key' => 'home.hero_stat1_label', 'value' => 'Properties Listed', 'description' => 'Hero stat 1 — label'],
            ['key' => 'home.hero_stat1_value', 'value' => '12,400+',           'description' => 'Hero stat 1 — number'],
            ['key' => 'home.hero_stat2_label', 'value' => 'States Covered',    'description' => 'Hero stat 2 — label'],
            ['key' => 'home.hero_stat2_value', 'value' => '48',                'description' => 'Hero stat 2 — number'],
            ['key' => 'home.hero_stat3_label', 'value' => 'Leases Signed',     'description' => 'Hero stat 3 — label'],
            ['key' => 'home.hero_stat3_value', 'value' => '38,000+',           'description' => 'Hero stat 3 — number'],

            // Platform stats block (4-column row)
            ['key' => 'home.stat1_label', 'value' => 'Active Properties',  'description' => 'Stats block — stat 1 label'],
            ['key' => 'home.stat1_num',   'value' => '12,400+',            'description' => 'Stats block — stat 1 number'],
            ['key' => 'home.stat1_sub',   'value' => 'Across 48 states',   'description' => 'Stats block — stat 1 sub-label'],
            ['key' => 'home.stat2_label', 'value' => 'Total Acres Listed', 'description' => 'Stats block — stat 2 label'],
            ['key' => 'home.stat2_num',   'value' => '4.2M',               'description' => 'Stats block — stat 2 number'],
            ['key' => 'home.stat2_sub',   'value' => 'And growing every week', 'description' => 'Stats block — stat 2 sub-label'],
            ['key' => 'home.stat3_label', 'value' => 'Leases Completed',   'description' => 'Stats block — stat 3 label'],
            ['key' => 'home.stat3_num',   'value' => '38,000+',            'description' => 'Stats block — stat 3 number'],
            ['key' => 'home.stat3_sub',   'value' => 'Every one e-signed', 'description' => 'Stats block — stat 3 sub-label'],
            ['key' => 'home.stat4_label', 'value' => 'Landowner Payouts',  'description' => 'Stats block — stat 4 label'],
            ['key' => 'home.stat4_num',   'value' => '$47M',               'description' => 'Stats block — stat 4 number'],
            ['key' => 'home.stat4_sub',   'value' => 'Paid out to date',   'description' => 'Stats block — stat 4 sub-label'],

            // Section visibility flags ('1' = visible, '0' = hidden)
            ['key' => 'home.section_almanac_enabled',      'value' => '1', 'description' => 'Show/hide § 02 Species Almanac section'],
            ['key' => 'home.section_stats_enabled',        'value' => '1', 'description' => 'Show/hide platform stats block'],
            ['key' => 'home.section_expedition_enabled',   'value' => '1', 'description' => 'Show/hide § 03 How It Works section'],
            ['key' => 'home.section_testimonials_enabled', 'value' => '1', 'description' => 'Show/hide testimonials section'],
            ['key' => 'home.section_cta_enabled',          'value' => '1', 'description' => 'Show/hide bottom CTA section'],

            // CTA section
            ['key' => 'home.cta_headline', 'value' => 'Your next season starts here.',     'description' => 'CTA section — main headline'],
            ['key' => 'home.cta_sub',      'value' => "Join thousands of landowners and hunters who've moved the entire leasing process — from search to signature — into one platform.", 'description' => 'CTA section — supporting text'],
        ];

        foreach ($settings as $setting) {
            TenantSettings::on('platform')->updateOrCreate(
                ['key' => $setting['key']],
                [
                    'value'       => $setting['value'],
                    'description' => $setting['description'],
                ]
            );
        }

        $this->command->info('Homepage settings seeded: ' . count($settings) . ' entries.');
    }
}
