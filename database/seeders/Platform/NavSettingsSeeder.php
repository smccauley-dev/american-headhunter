<?php

namespace Database\Seeders\Platform;

use App\Models\Platform\TenantSettings;
use Illuminate\Database\Seeder;

class NavSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            [
                'key'         => 'nav.links',
                'value'       => [
                    ['label' => 'Find Land',    'href' => '/properties',   'enabled' => true],
                    ['label' => 'Auctions',     'href' => '/auctions',      'enabled' => true],
                    ['label' => 'Outfitters',   'href' => '/outfitters',    'enabled' => true],
                    ['label' => 'How It Works', 'href' => '/how-it-works',  'enabled' => true],
                ],
                'description' => 'Main navigation links — label, href, and enabled flag per item',
            ],
            ['key' => 'nav.cta_label',    'value' => 'List Your Land →',        'description' => 'Nav bar CTA button label'],
            ['key' => 'nav.cta_href',     'value' => '/get-started?type=landowner', 'description' => 'Nav bar CTA button URL'],
            ['key' => 'nav.signin_label', 'value' => 'Sign In',                 'description' => 'Nav bar sign-in link label'],
            ['key' => 'nav.signin_href',  'value' => '/login',                  'description' => 'Nav bar sign-in link URL'],
        ];

        foreach ($settings as $s) {
            TenantSettings::on('platform')->updateOrCreate(
                ['key' => $s['key']],
                ['value' => $s['value'], 'description' => $s['description']],
            );
        }

        $this->command->info('Nav settings seeded: ' . count($settings) . ' entries.');
    }
}
