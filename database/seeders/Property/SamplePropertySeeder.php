<?php

namespace Database\Seeders\Property;

use App\Models\Property\Property;
use App\Models\Property\PropertyListing;
use App\Models\Property\PropertySpecies;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SamplePropertySeeder extends Seeder
{
    public function run(): void
    {
        // Own the sample properties with the real seeded test landowner rather
        // than the ghost placeholder, which has no identity row. TestUserSeeder
        // assigns this account a random UUID, so resolve it by email at runtime.
        $devOwnerId = DB::connection('identity')->table('users')
            ->where('email', 'landowner@test.local')
            ->value('id')
            ?? Property::PLACEHOLDER_OWNER_ID;

        $samples = [
            [
                'property' => [
                    'owner_user_id' => $devOwnerId,
                    'title'         => 'Brackettville Whitetail Ranch',
                    'slug'          => 'brackettville-whitetail-ranch',
                    'description'   => 'A premier 2,840-acre South Texas hunting ranch with exceptional genetics and decades of age-class management. Features a live water creek, 18 protein-fed stands, a 3-bedroom hunting cabin, and a skinning shed with cooler. Axis and hog opportunities available year-round.',
                    'status'        => 'active',
                    'state_code'    => 'TX',
                    'county'        => 'Kinney',
                    'total_acres'   => 2840.00,
                    'huntable_acres'=> 2600.00,
                    'center_lat'    => 29.31,
                    'center_lng'    => -100.42,
                ],
                'listing' => [
                    'listing_type'    => 'seasonal_lease',
                    'status'          => 'active',
                    'visibility'      => 'public',
                    'season_start'    => '2025-11-01',
                    'season_end'      => '2026-01-15',
                    'min_hunters'     => 2,
                    'max_hunters'     => 6,
                    'price_total'     => 14500.00,
                    'price_per_hunter'=> null,
                    'deposit_percent' => 25,
                ],
                'species' => [
                    ['code' => 'whitetail_deer', 'is_primary' => true],
                    ['code' => 'hog',            'is_primary' => false],
                    ['code' => 'dove',           'is_primary' => false],
                    ['code' => 'turkey',         'is_primary' => false],
                ],
            ],
            [
                'property' => [
                    'owner_user_id' => $devOwnerId,
                    'title'         => 'Flint Hills Elk Ridge',
                    'slug'          => 'flint-hills-elk-ridge',
                    'description'   => 'Native tallgrass prairie and wooded creek draws in the heart of the Kansas Flint Hills. An exceptional annual hunting lease with resident elk, whitetail, and Rio Grande turkey. 1,200 acres managed for trophy potential with strict harvest protocols.',
                    'status'        => 'active',
                    'state_code'    => 'KS',
                    'county'        => 'Chase',
                    'total_acres'   => 1200.00,
                    'huntable_acres'=> 1100.00,
                    'center_lat'    => 38.30,
                    'center_lng'    => -96.60,
                ],
                'listing' => [
                    'listing_type'    => 'annual_lease',
                    'status'          => 'active',
                    'visibility'      => 'public',
                    'season_start'    => '2025-09-01',
                    'season_end'      => '2026-02-28',
                    'min_hunters'     => 1,
                    'max_hunters'     => 4,
                    'price_total'     => 18000.00,
                    'price_per_hunter'=> null,
                    'deposit_percent' => 30,
                ],
                'species' => [
                    ['code' => 'elk',          'is_primary' => true],
                    ['code' => 'whitetail_deer','is_primary' => false],
                    ['code' => 'turkey',       'is_primary' => false],
                    ['code' => 'pheasant',     'is_primary' => false],
                ],
            ],
            [
                'property' => [
                    'owner_user_id' => $devOwnerId,
                    'title'         => 'Sabine River Bottomlands',
                    'slug'          => 'sabine-river-bottomlands',
                    'description'   => '680 acres of old-growth river bottom timber and flooded hardwood sloughs along the Sabine River. Premier East Texas waterfowl and hog hunting. Public boat access to the river included. Day hunts available — no overnight camp required.',
                    'status'        => 'active',
                    'state_code'    => 'TX',
                    'county'        => 'Sabine',
                    'total_acres'   => 680.00,
                    'huntable_acres'=> 620.00,
                    'center_lat'    => 31.35,
                    'center_lng'    => -93.75,
                ],
                'listing' => [
                    'listing_type'    => 'day_hunt',
                    'status'          => 'active',
                    'visibility'      => 'public',
                    'season_start'    => '2025-11-01',
                    'season_end'      => '2026-01-31',
                    'min_hunters'     => 1,
                    'max_hunters'     => 4,
                    'price_total'     => null,
                    'price_per_hunter'=> 175.00,
                    'deposit_percent' => null,
                ],
                'species' => [
                    ['code' => 'waterfowl',    'is_primary' => true],
                    ['code' => 'hog',          'is_primary' => false],
                    ['code' => 'dove',         'is_primary' => false],
                    ['code' => 'whitetail_deer','is_primary' => false],
                ],
            ],
            [
                'property' => [
                    'owner_user_id' => $devOwnerId,
                    'title'         => 'Appalachian Turkey Run',
                    'slug'          => 'appalachian-turkey-run',
                    'description'   => '920 acres of mixed hardwood ridge-and-hollow terrain in the West Virginia highlands. Exceptional Eastern turkey hunting with resident black bear and whitetail. Private road access. Rustic camp provided. Non-motorized only on designated ridges.',
                    'status'        => 'active',
                    'state_code'    => 'WV',
                    'county'        => 'Preston',
                    'total_acres'   => 920.00,
                    'huntable_acres'=> 880.00,
                    'center_lat'    => 39.45,
                    'center_lng'    => -79.35,
                ],
                'listing' => [
                    'listing_type'    => 'seasonal_lease',
                    'status'          => 'active',
                    'visibility'      => 'public',
                    'season_start'    => '2025-09-01',
                    'season_end'      => '2026-05-31',
                    'min_hunters'     => 1,
                    'max_hunters'     => 3,
                    'price_total'     => 6800.00,
                    'price_per_hunter'=> null,
                    'deposit_percent' => 20,
                ],
                'species' => [
                    ['code' => 'turkey',       'is_primary' => true],
                    ['code' => 'whitetail_deer','is_primary' => false],
                    ['code' => 'bear',         'is_primary' => false],
                    ['code' => 'squirrel',     'is_primary' => false],
                ],
            ],
        ];

        foreach ($samples as $data) {
            $property = Property::on('property')->updateOrCreate(
                ['slug' => $data['property']['slug']],
                $data['property'],
            );

            if ($property->wasRecentlyCreated) {
                PropertyListing::on('property')->create(array_merge(
                    ['property_id' => $property->id],
                    $data['listing'],
                ));

                foreach ($data['species'] as $sp) {
                    PropertySpecies::on('property')->create([
                        'property_id' => $property->id,
                        'species_code'=> $sp['code'],
                        'is_primary'  => $sp['is_primary'],
                    ]);
                }

                $this->command->info("  Created: {$data['property']['title']}");
            } else {
                $this->command->info("  Updated: {$data['property']['title']} (coordinates set)");
            }
        }

        $this->command->info('Sample properties seeded: ' . count($samples) . ' properties.');
    }
}
