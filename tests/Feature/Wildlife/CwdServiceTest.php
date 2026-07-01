<?php

namespace Tests\Feature\Wildlife;

use App\Services\Property\GeospatialService;
use App\Services\Wildlife\CwdService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * CwdService — the compliance gate. Zone geometry lives in DB 13 (mocked here);
 * this service joins it to the DB-5 regulatory metadata and records the
 * acknowledgment. Real rows on the wildlife connection for the ack path.
 */
class CwdServiceTest extends TestCase
{
    private string $harvestId;

    private string $positiveZoneId;

    private string $surveillanceZoneId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->harvestId = DB::connection('wildlife')->table('harvest_logs')->insertGetId([
            'lease_id' => (string) Str::uuid(),
            'user_id' => (string) Str::uuid(),
            'property_id' => (string) Str::uuid(),
            'species_code' => 'whitetail_deer',
            'harvest_date' => '2026-11-15',
            'weapon_type' => 'rifle',
        ], 'id');

        $this->positiveZoneId = DB::connection('wildlife')->table('cwd_zones')->insertGetId([
            'state_code' => 'WI',
            'zone_name' => 'CWD Positive Test Zone',
            'zone_type' => 'positive',
            'effective_date' => '2026-01-01',
        ], 'id');

        $this->surveillanceZoneId = DB::connection('wildlife')->table('cwd_zones')->insertGetId([
            'state_code' => 'WI',
            'zone_name' => 'CWD Surveillance Test Zone',
            'zone_type' => 'surveillance',
            'effective_date' => '2026-01-01',
        ], 'id');
    }

    protected function tearDown(): void
    {
        DB::connection('wildlife')->table('cwd_acknowledgments')->where('harvest_log_id', $this->harvestId)->delete();
        DB::connection('wildlife')->table('harvest_logs')->where('id', $this->harvestId)->delete();
        DB::connection('wildlife')->table('cwd_zones')
            ->whereIn('id', [$this->positiveZoneId, $this->surveillanceZoneId])->delete();

        parent::tearDown();
    }

    public function test_only_positive_zones_require_acknowledgment(): void
    {
        $geo = Mockery::mock(GeospatialService::class);
        $geo->shouldReceive('getCwdZonesForPoint')->andReturn([
            (object) ['state_code' => 'WI', 'zone_name' => 'CWD Positive Test Zone', 'zone_type' => 'positive', 'effective_date' => '2026-01-01'],
            (object) ['state_code' => 'WI', 'zone_name' => 'CWD Surveillance Test Zone', 'zone_type' => 'surveillance', 'effective_date' => '2026-01-01'],
        ]);
        $this->app->instance(GeospatialService::class, $geo);

        $required = app(CwdService::class)->zonesRequiringAcknowledgment(-89.5, 43.1);

        $this->assertCount(1, $required);
        $this->assertSame('positive', $required->first()->zone_type);
    }

    public function test_acknowledge_is_idempotent_and_writes_one_row(): void
    {
        $svc = app(CwdService::class);
        $userId = (string) Str::uuid();

        $first = $svc->acknowledge($userId, $this->harvestId, $this->positiveZoneId);
        $second = $svc->acknowledge($userId, $this->harvestId, $this->positiveZoneId);

        $this->assertSame($first->id, $second->id, 're-acknowledging returns the same record');
        $this->assertSame(1, DB::connection('wildlife')->table('cwd_acknowledgments')
            ->where('harvest_log_id', $this->harvestId)->count());
    }
}
