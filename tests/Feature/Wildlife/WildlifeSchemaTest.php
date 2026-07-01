<?php

namespace Tests\Feature\Wildlife;

use App\Services\Property\GeospatialService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * DB 5 (Wildlife) schema verification — locks in Phase 6.1.
 *
 * Runs against the real `wildlife` PostgreSQL connection (not the sqlite test DB)
 * because the assertions cover Postgres-only behaviour: gen_random_uuid()
 * defaults, the trigger_set_updated_at() trigger, CHECK constraints, the two
 * offline-dedup partial unique indexes, chk_harvest_quotas_not_exceeded, the
 * atomic quota UPDATE, and the cross-DB harvest-location write into DB 13.
 *
 * Eloquent models arrive in Phase 6.2, so this suite drives the tables directly
 * via the query builder. No DatabaseTransactions: the updated_at trigger uses
 * NOW(), frozen for the life of a transaction, so rows are cleaned in tearDown.
 */
class WildlifeSchemaTest extends TestCase
{
    /** @var array<int,string> */
    private array $harvestIds = [];

    /** @var array<int,string> */
    private array $sightingIds = [];

    /** @var array<int,string> */
    private array $quotaIds = [];

    /** @var array<int,string> */
    private array $harvestLocationIds = [];

    protected function tearDown(): void
    {
        $wildlife = DB::connection('wildlife');
        // trophies + cwd_acknowledgments cascade off harvest_logs.
        if ($this->harvestIds) {
            $wildlife->table('harvest_logs')->whereIn('id', $this->harvestIds)->delete();
        }
        if ($this->sightingIds) {
            $wildlife->table('wildlife_sightings')->whereIn('id', $this->sightingIds)->delete();
        }
        if ($this->quotaIds) {
            $wildlife->table('harvest_quotas')->whereIn('id', $this->quotaIds)->delete();
        }

        if ($this->harvestLocationIds) {
            DB::connection('geospatial')->table('harvest_locations')
                ->whereIn('id', $this->harvestLocationIds)->delete();
        }

        try {
            $wildlife->disconnect();
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    /**
     * Insert a harvest_logs row via the query builder and return its server-generated id.
     */
    private function makeHarvest(array $overrides = []): string
    {
        $row = array_merge([
            'lease_id' => (string) Str::uuid(),
            'user_id' => (string) Str::uuid(),
            'property_id' => (string) Str::uuid(),
            'species_code' => 'whitetail_deer',
            'harvest_date' => now()->toDateString(),
            'weapon_type' => 'bow',
        ], $overrides);

        $id = DB::connection('wildlife')->table('harvest_logs')->insertGetId($row, 'id');
        $this->harvestIds[] = $id;

        return $id;
    }

    public function test_harvest_log_gets_server_generated_uuid(): void
    {
        $id = $this->makeHarvest();
        $this->assertTrue(Str::isUuid($id), 'harvest_logs.id should be a server-generated UUID');
    }

    public function test_harvest_log_rejects_unknown_species_code(): void
    {
        $this->expectException(QueryException::class);
        $this->makeHarvest(['species_code' => 'unicorn']);
    }

    public function test_harvest_log_rejects_unknown_weapon_type(): void
    {
        $this->expectException(QueryException::class);
        $this->makeHarvest(['weapon_type' => 'slingshot']);
    }

    public function test_updated_at_trigger_overwrites_client_value(): void
    {
        $id = $this->makeHarvest();

        DB::connection('wildlife')->table('harvest_logs')
            ->where('id', $id)
            ->update(['notes' => 'edited', 'updated_at' => '2000-01-01 00:00:00']);

        $updatedAt = DB::connection('wildlife')->table('harvest_logs')->where('id', $id)->value('updated_at');
        $this->assertNotSame('2000', substr((string) $updatedAt, 0, 4), 'trigger should overwrite updated_at');
    }

    public function test_offline_dedup_partial_unique_on_harvest_logs(): void
    {
        $userId = (string) Str::uuid();
        $local = (string) Str::uuid();

        $this->makeHarvest(['user_id' => $userId, 'local_record_id' => $local]);

        $this->expectException(QueryException::class); // uq_harvest_logs_user_local_record
        $this->makeHarvest(['user_id' => $userId, 'local_record_id' => $local]);
    }

    public function test_null_local_record_id_does_not_collide(): void
    {
        $userId = (string) Str::uuid();

        // Two server-authored rows (local_record_id NULL) for the same user are fine —
        // the unique index is partial (WHERE local_record_id IS NOT NULL).
        $this->makeHarvest(['user_id' => $userId]);
        $this->makeHarvest(['user_id' => $userId]);

        $count = DB::connection('wildlife')->table('harvest_logs')->where('user_id', $userId)->count();
        $this->assertSame(2, $count);
    }

    public function test_offline_dedup_partial_unique_on_wildlife_sightings(): void
    {
        $userId = (string) Str::uuid();
        $local = (string) Str::uuid();

        $make = function () use ($userId, $local) {
            $id = DB::connection('wildlife')->table('wildlife_sightings')->insertGetId([
                'lease_id' => (string) Str::uuid(),
                'user_id' => $userId,
                'property_id' => (string) Str::uuid(),
                'species_code' => 'turkey',
                'sighting_date' => now()->toDateString(),
                'local_record_id' => $local,
            ], 'id');
            $this->sightingIds[] = $id;
        };

        $make();
        $this->expectException(QueryException::class); // uq_wildlife_sightings_user_local_record
        $make();
    }

    public function test_harvest_quota_check_constraint_rejects_over_max(): void
    {
        $this->expectException(QueryException::class); // chk_harvest_quotas_not_exceeded
        $this->makeQuota(maxHarvest: 5, currentHarvest: 6);
    }

    public function test_atomic_quota_increment_stops_at_max(): void
    {
        $id = $this->makeQuota(maxHarvest: 2, currentHarvest: 1);

        // The QuotaService pattern: only increments while current < max.
        $increment = fn () => DB::connection('wildlife')->update(
            'UPDATE harvest_quotas SET current_harvest = current_harvest + 1
             WHERE id = ? AND current_harvest < max_harvest',
            [$id]
        );

        $this->assertSame(1, $increment(), 'first increment should take the last slot');
        $this->assertSame(0, $increment(), 'second increment must be rejected — quota full');

        $current = DB::connection('wildlife')->table('harvest_quotas')->where('id', $id)->value('current_harvest');
        $this->assertSame(2, (int) $current);
    }

    public function test_quota_unique_treats_null_lease_as_property_wide(): void
    {
        $propertyId = (string) Str::uuid();

        $this->makeQuota(propertyId: $propertyId, leaseId: null);

        $this->expectException(QueryException::class); // uq_harvest_quotas_property_lease_species_year (COALESCE)
        $this->makeQuota(propertyId: $propertyId, leaseId: null);
    }

    public function test_trophy_scoring_system_check_and_cascade(): void
    {
        $harvestId = $this->makeHarvest();

        DB::connection('wildlife')->table('trophies')->insert([
            'harvest_log_id' => $harvestId,
            'scoring_system' => 'boone_crockett',
            'gross_score' => 168.25,
            'net_score' => 160.00,
            'is_official' => true,
        ]);
        $this->assertSame(1, DB::connection('wildlife')->table('trophies')->where('harvest_log_id', $harvestId)->count());

        // Bad scoring system rejected.
        try {
            DB::connection('wildlife')->table('trophies')->insert([
                'harvest_log_id' => $harvestId,
                'scoring_system' => 'made_up',
            ]);
            $this->fail('expected CHECK violation on scoring_system');
        } catch (QueryException) {
            // expected
        }

        // Deleting the harvest cascades the trophy.
        DB::connection('wildlife')->table('harvest_logs')->where('id', $harvestId)->delete();
        $this->assertSame(0, DB::connection('wildlife')->table('trophies')->where('harvest_log_id', $harvestId)->count());
    }

    public function test_cwd_acknowledgment_requires_valid_harvest_and_zone(): void
    {
        $harvestId = $this->makeHarvest();
        $zoneId = DB::connection('wildlife')->table('cwd_zones')->insertGetId([
            'state_code' => 'WI',
            'zone_name' => 'Test Positive Zone',
            'zone_type' => 'positive',
            'effective_date' => now()->toDateString(),
        ], 'id');

        DB::connection('wildlife')->table('cwd_acknowledgments')->insert([
            'user_id' => (string) Str::uuid(),
            'harvest_log_id' => $harvestId,
            'cwd_zone_id' => $zoneId,
        ]);
        $this->assertSame(1, DB::connection('wildlife')->table('cwd_acknowledgments')->where('harvest_log_id', $harvestId)->count());

        // A bogus harvest_log_id violates the same-DB FK.
        try {
            DB::connection('wildlife')->table('cwd_acknowledgments')->insert([
                'user_id' => (string) Str::uuid(),
                'harvest_log_id' => (string) Str::uuid(),
                'cwd_zone_id' => $zoneId,
            ]);
            $this->fail('expected FK violation on harvest_log_id');
        } catch (QueryException) {
            // expected
        } finally {
            // Clear the acknowledgment (FK to cwd_zones is not cascade) before the zone.
            DB::connection('wildlife')->table('cwd_acknowledgments')->where('cwd_zone_id', $zoneId)->delete();
            DB::connection('wildlife')->table('cwd_zones')->where('id', $zoneId)->delete();
        }
    }

    public function test_store_harvest_location_writes_point_to_db13(): void
    {
        $harvestId = $this->makeHarvest();

        $locationId = app(GeospatialService::class)->storeHarvestLocation($harvestId, -89.5, 43.1, 8);
        $this->harvestLocationIds[] = $locationId;

        $this->assertTrue(Str::isUuid($locationId));

        $row = DB::connection('geospatial')->selectOne(
            'SELECT harvest_log_id, accuracy_meters,
                    ST_X(location) AS lng, ST_Y(location) AS lat
             FROM harvest_locations WHERE id = ?',
            [$locationId]
        );

        $this->assertSame($harvestId, $row->harvest_log_id);
        $this->assertSame(8, (int) $row->accuracy_meters);
        $this->assertEqualsWithDelta(-89.5, (float) $row->lng, 0.0001);
        $this->assertEqualsWithDelta(43.1, (float) $row->lat, 0.0001);
    }

    private function makeQuota(?string $propertyId = null, ?string $leaseId = null, int $maxHarvest = 10, int $currentHarvest = 0): string
    {
        $id = DB::connection('wildlife')->table('harvest_quotas')->insertGetId([
            'property_id' => $propertyId ?? (string) Str::uuid(),
            'lease_id' => $leaseId,
            'species_code' => 'whitetail_deer',
            'season_year' => 2026,
            'max_harvest' => $maxHarvest,
            'current_harvest' => $currentHarvest,
        ], 'id');
        $this->quotaIds[] = $id;

        return $id;
    }
}
