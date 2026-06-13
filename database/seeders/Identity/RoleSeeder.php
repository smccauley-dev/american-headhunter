<?php

namespace Database\Seeders\Identity;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            // Customer-facing roles
            ['name' => 'hunter',      'display_name' => 'Hunter',               'description' => 'Hunting lease applicant and member',          'is_system' => true],
            ['name' => 'landowner',   'display_name' => 'Landowner',            'description' => 'Property owner listing land for lease',       'is_system' => true],
            ['name' => 'club_admin',  'display_name' => 'Club Administrator',   'description' => 'Manages a hunting club and its members',      'is_system' => true],
            ['name' => 'outfitter',   'display_name' => 'Outfitter',            'description' => 'Guided hunt and outfitter service provider',  'is_system' => true],
            ['name' => 'consultant',  'display_name' => 'Hunting Consultant',   'description' => 'Licensed hunting consultant',                 'is_system' => true],
            ['name' => 'seller',      'display_name' => 'Equipment Seller',     'description' => 'Marketplace equipment seller',                'is_system' => true],
            // Admin panel roles
            ['name' => 'staff',          'display_name' => 'Platform Staff',       'description' => 'General platform staff — legacy admin role',                               'is_system' => true],
            ['name' => 'super_admin',    'display_name' => 'Super Administrator',   'description' => 'Full unrestricted access to all systems',                                  'is_system' => true],
            ['name' => 'global_admin',   'display_name' => 'Global Administrator',  'description' => 'Full content and user management — no system configuration access',        'is_system' => true],
            ['name' => 'property_admin', 'display_name' => 'Property Administrator','description' => 'Manages properties, amenities, and listings only',                         'is_system' => true],
            ['name' => 'security_admin', 'display_name' => 'Security Administrator','description' => 'Manages admin users, audit logs, and security settings',                   'is_system' => true],
            ['name' => 'article_admin',  'display_name' => 'Article Administrator', 'description' => 'Creates and manages hunting articles, reviews, and editorial content',     'is_system' => true],
        ];

        foreach ($roles as $role) {
            DB::connection('identity')->table('roles')->upsert(
                array_merge($role, ['id' => (string) Str::uuid(), 'created_at' => now()]),
                ['name'],
                ['display_name']
            );
        }

        $this->command->info('Roles seeded.');
    }
}
