<?php

namespace Database\Seeders\Identity;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TestUserSeeder extends Seeder
{
    private const PASSWORD = 'Password1!local';

    private array $users = [
        // Customer-facing test users
        ['email' => 'hunter@test.local',     'account_type' => 'hunter',     'first_name' => 'Hunter',     'last_name' => 'Test',     'role' => 'hunter'],
        ['email' => 'landowner@test.local',  'account_type' => 'landowner',  'first_name' => 'Landowner',  'last_name' => 'Test',     'role' => 'landowner'],
        ['email' => 'club@test.local',       'account_type' => 'club',       'first_name' => 'Club',       'last_name' => 'Admin',    'role' => 'club_admin'],
        ['email' => 'outfitter@test.local',  'account_type' => 'outfitter',  'first_name' => 'Outfitter',  'last_name' => 'Test',     'role' => 'outfitter'],
        ['email' => 'consultant@test.local', 'account_type' => 'consultant', 'first_name' => 'Consultant', 'last_name' => 'Test',     'role' => 'consultant'],
        ['email' => 'seller@test.local',     'account_type' => 'seller',     'first_name' => 'Seller',     'last_name' => 'Test',     'role' => 'seller'],
        // Admin panel test users — one per role
        ['email' => 'staff@test.local',         'account_type' => 'staff', 'first_name' => 'Super',    'last_name' => 'Admin',    'role' => 'super_admin'],
        ['email' => 'global@test.local',        'account_type' => 'staff', 'first_name' => 'Global',   'last_name' => 'Admin',    'role' => 'global_admin'],
        ['email' => 'property@test.local',      'account_type' => 'staff', 'first_name' => 'Property', 'last_name' => 'Admin',    'role' => 'property_admin'],
        ['email' => 'security@test.local',      'account_type' => 'staff', 'first_name' => 'Security', 'last_name' => 'Admin',    'role' => 'security_admin'],
        ['email' => 'articles@test.local',      'account_type' => 'staff', 'first_name' => 'Article',  'last_name' => 'Admin',    'role' => 'article_admin'],
    ];

    public function run(): void
    {
        $passwordHash = Hash::make(self::PASSWORD);

        foreach ($this->users as $userData) {
            $existing = DB::connection('identity')
                ->table('users')
                ->where('email', $userData['email'])
                ->first();

            if ($existing) {
                continue;
            }

            $userId = (string) Str::uuid();
            $now    = now();

            DB::connection('identity')->table('users')->insert([
                'id'                 => $userId,
                'email'              => $userData['email'],
                'email_verified_at'  => $now,
                'password_hash'      => $passwordHash,
                'status'             => 'active',
                'account_type'       => $userData['account_type'],
                'trust_score'        => 50,
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);

            DB::connection('identity')->table('user_profiles')->insert([
                'id'         => (string) Str::uuid(),
                'user_id'    => $userId,
                'first_name' => $userData['first_name'],
                'last_name'  => $userData['last_name'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // Assign role from explicit 'role' key
            $role = DB::connection('identity')
                ->table('roles')
                ->where('name', $userData['role'])
                ->first();

            if ($role) {
                DB::connection('identity')->table('user_roles')->insert([
                    'user_id'    => $userId,
                    'role_id'    => $role->id,
                    'granted_at' => $now,
                ]);
            }

            // Record ToS consent
            DB::connection('identity')->table('consent_log')->insert([
                'id'           => (string) Str::uuid(),
                'user_id'      => $userId,
                'consent_type' => 'terms_of_service',
                'granted'      => true,
                'version'      => '2026-01-01',
                'created_at'   => $now,
            ]);
        }

        $this->command->info('Test users seeded — password: ' . self::PASSWORD);
    }
}
