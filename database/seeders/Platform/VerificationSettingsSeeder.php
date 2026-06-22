<?php

namespace Database\Seeders\Platform;

use App\Models\Platform\TenantSettings;
use Illuminate\Database\Seeder;

class VerificationSettingsSeeder extends Seeder
{
    public function run(): void
    {
        // The method switch for service-status verification. Read by the signup
        // step + ServiceVerificationService to decide which method(s) to offer.
        // Values: 'manual' (document upload + admin review), 'id_me' (automated
        // OAuth), or 'both' (offer ID.me with manual as fallback). Defaults to
        // 'manual' so verification works with no third-party integration; flip to
        // 'id_me'/'both' from admin once ID.me is wired — no deploy needed.
        $settings = [
            ['key' => 'verification.veteran.method',         'value' => 'manual', 'description' => 'Veteran verification method: manual | id_me | both'],
            ['key' => 'verification.first_responder.method', 'value' => 'manual', 'description' => 'First responder verification method: manual | id_me | both'],
        ];

        foreach ($settings as $s) {
            TenantSettings::on('platform')->updateOrCreate(
                ['key' => $s['key']],
                ['value' => $s['value'], 'description' => $s['description']],
            );
        }

        $this->command->info('Verification settings seeded: ' . count($settings) . ' entries.');
    }
}
