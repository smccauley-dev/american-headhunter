<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'property';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE property_amenities (
                id         UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                name       VARCHAR(100) NOT NULL,
                category   VARCHAR(20)  NOT NULL
                               CHECK (category IN ('accommodation', 'access', 'water', 'stand', 'food_plot', 'other')),
                icon_name  VARCHAR(50)  NULL,
                created_at TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            );

            CREATE UNIQUE INDEX uq_property_amenities_name ON property_amenities (name);
            CREATE        INDEX idx_property_amenities_cat ON property_amenities (category);

            CREATE TABLE property_amenity_listings (
                listing_id UUID         NOT NULL REFERENCES property_listings (id) ON DELETE CASCADE,
                amenity_id UUID         NOT NULL REFERENCES property_amenities (id) ON DELETE CASCADE,
                notes      VARCHAR(255) NULL,
                PRIMARY KEY (listing_id, amenity_id)
            );

            CREATE INDEX idx_property_amenity_listings_amenity ON property_amenity_listings (amenity_id);
        SQL);

        // Seed amenities using PHP to keep types clean
        $rows = [
            ['Hunting cabin',      'accommodation', 'cabin'],
            ['Electricity',        'accommodation', 'electricity'],
            ['Running water',      'accommodation', 'water_tap'],
            ['Bunk beds',          'accommodation', 'bunk'],
            ['Outdoor kitchen',    'accommodation', 'kitchen'],
            ['ATV trails',         'access',        'atv'],
            ['Paved road access',  'access',        'road'],
            ['Gravel road access', 'access',        'road_gravel'],
            ['4WD required',       'access',        'four_wheel'],
            ['Boat access',        'access',        'boat'],
            ['Pond / lake',        'water',         'pond'],
            ['Creek',              'water',         'creek'],
            ['River',              'water',         'river'],
            ['Wetlands',           'water',         'wetlands'],
            ['Elevated box blinds','stand',         'box_blind'],
            ['Lock-on stands',     'stand',         'lock_on'],
            ['Ground blinds',      'stand',         'ground_blind'],
            ['Shooting lanes',     'stand',         'lanes'],
            ['Food plots',         'food_plot',     'food_plot'],
            ['Deer feeders',       'food_plot',     'feeder'],
            ['Corn fields',        'food_plot',     'corn'],
            ['Fruit orchards',     'food_plot',     'orchard'],
            ['Cell service',       'other',         'cell'],
            ['WiFi',               'other',         'wifi'],
            ['Trash removal',      'other',         'trash'],
            ['Firewood provided',  'other',         'firewood'],
        ];

        $conn = DB::connection($this->connection);
        foreach (array_chunk($rows, 50) as $chunk) {
            $conn->table('property_amenities')->insert(array_map(fn($r) => [
                'name'      => $r[0],
                'category'  => $r[1],
                'icon_name' => $r[2],
            ], $chunk));
        }
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(
            'DROP TABLE IF EXISTS property_amenity_listings CASCADE;
             DROP TABLE IF EXISTS property_amenities CASCADE;'
        );
    }
};
