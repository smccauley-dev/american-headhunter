<?php

namespace Database\Seeders\Identity;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PermissionSeeder extends Seeder
{
    private array $permissions = [
        // Lease
        ['category' => 'lease',    'name' => 'lease.view',           'display_name' => 'View Leases'],
        ['category' => 'lease',    'name' => 'lease.apply',          'display_name' => 'Apply for Lease'],
        ['category' => 'lease',    'name' => 'lease.manage',         'display_name' => 'Manage Leases'],
        ['category' => 'lease',    'name' => 'lease.terminate',      'display_name' => 'Terminate Lease'],
        // Property
        ['category' => 'property', 'name' => 'property.view',        'display_name' => 'View Properties'],
        ['category' => 'property', 'name' => 'property.create',      'display_name' => 'Create Property'],
        ['category' => 'property', 'name' => 'property.manage',      'display_name' => 'Manage Properties'],
        ['category' => 'property', 'name' => 'property.list',        'display_name' => 'List Property for Lease'],
        // Harvest
        ['category' => 'harvest',  'name' => 'harvest.log',          'display_name' => 'Log Harvest'],
        ['category' => 'harvest',  'name' => 'harvest.view_own',     'display_name' => 'View Own Harvests'],
        ['category' => 'harvest',  'name' => 'harvest.view_all',     'display_name' => 'View All Harvests'],
        // Billing
        ['category' => 'billing',  'name' => 'billing.view_own',     'display_name' => 'View Own Billing'],
        ['category' => 'billing',  'name' => 'billing.manage',       'display_name' => 'Manage Billing'],
        ['category' => 'billing',  'name' => 'billing.payout',       'display_name' => 'Manage Payouts'],
        // Auction
        ['category' => 'auction',  'name' => 'auction.bid',          'display_name' => 'Place Auction Bids'],
        ['category' => 'auction',  'name' => 'auction.create',       'display_name' => 'Create Auctions'],
        ['category' => 'auction',  'name' => 'auction.manage',       'display_name' => 'Manage Auctions'],
        // Admin
        ['category' => 'admin',    'name' => 'admin.users',          'display_name' => 'Manage Users'],
        ['category' => 'admin',    'name' => 'admin.reports',        'display_name' => 'View Reports'],
        ['category' => 'admin',    'name' => 'admin.platform',       'display_name' => 'Manage Platform Settings'],
        // Check-in
        ['category' => 'checkin',  'name' => 'checkin.create',       'display_name' => 'Check In'],
        ['category' => 'checkin',  'name' => 'checkin.view',         'display_name' => 'View Check-ins'],
        // Wildlife
        ['category' => 'wildlife', 'name' => 'wildlife.view',        'display_name' => 'View Wildlife Data'],
        ['category' => 'wildlife', 'name' => 'wildlife.manage',      'display_name' => 'Manage Wildlife Records'],
        // Documents
        ['category' => 'documents','name' => 'documents.upload',     'display_name' => 'Upload Documents'],
        ['category' => 'documents','name' => 'documents.sign',       'display_name' => 'Sign Documents'],
    ];

    public function run(): void
    {
        foreach ($this->permissions as $permission) {
            DB::connection('identity')->table('permissions')->upsert(
                array_merge($permission, ['id' => (string) Str::uuid(), 'created_at' => now()]),
                ['name'],
                ['display_name']
            );
        }

        $this->command->info('Permissions seeded.');
    }
}
