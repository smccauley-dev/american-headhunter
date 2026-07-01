<?php

namespace Database\Seeders\Wildlife;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds DB 5 reference data: hunting `seasons` and CWD `cwd_zones` metadata.
 *
 * Idempotent — keyed on each table's natural unique index so re-seeding is a
 * no-op and never disturbs admin-maintained rows. Eloquent models arrive in
 * Phase 6.2, so this writes via the query builder on the `wildlife` connection.
 *
 * This is a representative starter set for dev, not authoritative regulatory
 * data — real seasons/zones are loaded per-state from wildlife-agency feeds.
 */
class WildlifeReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedSeasons();
        $this->seedCwdZones();
    }

    private function seedSeasons(): void
    {
        $conn = DB::connection('wildlife');
        $year = 2026;

        $seasons = [
            // [state, species, name, type, start, end]
            ['WI', 'whitetail_deer', 'Archery Deer',      'archery',      "$year-09-12", ($year + 1).'-01-04'],
            ['WI', 'whitetail_deer', 'Gun Deer',          'rifle',        "$year-11-21", "$year-11-29"],
            ['WI', 'whitetail_deer', 'Muzzleloader Deer', 'muzzleloader', "$year-11-30", "$year-12-09"],
            ['WI', 'turkey',         'Fall Turkey',       'general',      "$year-09-12", "$year-11-20"],
            ['TX', 'whitetail_deer', 'General Deer',      'general',      "$year-11-07", ($year + 1).'-01-03'],
            ['TX', 'whitetail_deer', 'Archery Deer',      'archery',      "$year-10-03", "$year-11-06"],
            ['TX', 'hog',            'Feral Hog',         'general',      "$year-01-01", "$year-12-31"],
            ['MT', 'elk',            'General Elk',       'general',      "$year-10-24", "$year-11-29"],
            ['MT', 'mule_deer',      'General Deer',      'general',      "$year-10-24", "$year-11-29"],
        ];

        foreach ($seasons as [$state, $species, $name, $type, $start, $end]) {
            $exists = $conn->table('seasons')
                ->where('state_code', $state)
                ->where('species_code', $species)
                ->where('season_type', $type)
                ->where('year', $year)
                ->exists();

            if ($exists) {
                continue;
            }

            $conn->table('seasons')->insert([
                'state_code' => $state,
                'species_code' => $species,
                'season_name' => $name,
                'season_type' => $type,
                'start_date' => $start,
                'end_date' => $end,
                'year' => $year,
            ]);
        }
    }

    private function seedCwdZones(): void
    {
        $conn = DB::connection('wildlife');

        $zones = [
            // [state, name, type, regulations, effective]
            ['WI', 'Southern Farmland CWD Zone', 'positive', 'Mandatory sampling for adult deer. In-person or drop-off registration required within 48 hours. No carcass transport out of zone.', '2026-01-01'],
            ['WI', 'Northern Surveillance Zone', 'surveillance', 'Voluntary sampling encouraged. Report any deer appearing sick to the state wildlife agency.', '2026-01-01'],
            ['MT', 'Region 2 Management Zone', 'management', 'Sampling requested at designated check stations. Follow carcass transport restrictions.', '2026-01-01'],
        ];

        foreach ($zones as [$state, $name, $type, $regs, $effective]) {
            $exists = $conn->table('cwd_zones')
                ->where('state_code', $state)
                ->where('zone_name', $name)
                ->exists();

            if ($exists) {
                continue;
            }

            $conn->table('cwd_zones')->insert([
                'state_code' => $state,
                'zone_name' => $name,
                'zone_type' => $type,
                'regulations' => $regs,
                'effective_date' => $effective,
            ]);
        }
    }
}
