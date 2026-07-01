<?php

namespace Tests\Feature\Wildlife;

use App\Services\Wildlife\QuotaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * QuotaService — resolution (lease-specific overrides property-wide), atomic
 * consumption, and remaining. Real rows on the wildlife connection.
 */
class QuotaServiceTest extends TestCase
{
    private string $propertyId;

    private string $leaseId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->propertyId = (string) Str::uuid();
        $this->leaseId = (string) Str::uuid();
    }

    protected function tearDown(): void
    {
        DB::connection('wildlife')->table('harvest_quotas')->where('property_id', $this->propertyId)->delete();
        parent::tearDown();
    }

    private function quota(?string $leaseId, string $species, int $max, int $current = 0): void
    {
        DB::connection('wildlife')->table('harvest_quotas')->insert([
            'id' => (string) Str::uuid(),
            'property_id' => $this->propertyId,
            'lease_id' => $leaseId,
            'species_code' => $species,
            'season_year' => 2026,
            'max_harvest' => $max,
            'current_harvest' => $current,
        ]);
    }

    public function test_lease_specific_quota_takes_precedence_over_property_wide(): void
    {
        $this->quota(null, 'whitetail_deer', 10);
        $this->quota($this->leaseId, 'whitetail_deer', 2);

        $applicable = app(QuotaService::class)->applicable($this->propertyId, $this->leaseId, 'whitetail_deer', 2026);

        $this->assertNotNull($applicable);
        $this->assertSame($this->leaseId, $applicable->lease_id);
        $this->assertSame(2, $applicable->max_harvest);
    }

    public function test_falls_back_to_property_wide_when_no_lease_quota(): void
    {
        $this->quota(null, 'turkey', 4);

        $applicable = app(QuotaService::class)->applicable($this->propertyId, $this->leaseId, 'turkey', 2026);

        $this->assertNotNull($applicable);
        $this->assertNull($applicable->lease_id);
    }

    public function test_unquotaed_species_is_always_allowed(): void
    {
        $svc = app(QuotaService::class);

        $this->assertNull($svc->remaining($this->propertyId, $this->leaseId, 'elk', 2026));
        $this->assertTrue($svc->tryConsume($this->propertyId, $this->leaseId, 'elk', 2026));
    }

    public function test_try_consume_stops_at_max(): void
    {
        $this->quota($this->leaseId, 'whitetail_deer', 2);
        $svc = app(QuotaService::class);

        $this->assertTrue($svc->tryConsume($this->propertyId, $this->leaseId, 'whitetail_deer', 2026));
        $this->assertSame(1, $svc->remaining($this->propertyId, $this->leaseId, 'whitetail_deer', 2026));
        $this->assertTrue($svc->tryConsume($this->propertyId, $this->leaseId, 'whitetail_deer', 2026));
        $this->assertSame(0, $svc->remaining($this->propertyId, $this->leaseId, 'whitetail_deer', 2026));
        $this->assertFalse($svc->tryConsume($this->propertyId, $this->leaseId, 'whitetail_deer', 2026), 'a full quota rejects');
    }

    public function test_release_floors_at_zero(): void
    {
        $this->quota($this->leaseId, 'hog', 3, current: 0);
        $svc = app(QuotaService::class);

        $svc->release($this->propertyId, $this->leaseId, 'hog', 2026);

        $this->assertSame(3, $svc->remaining($this->propertyId, $this->leaseId, 'hog', 2026));
    }

    public function test_list_for_lease_dedups_species_preferring_lease_specific(): void
    {
        $this->quota(null, 'whitetail_deer', 10, current: 4);
        $this->quota($this->leaseId, 'whitetail_deer', 2, current: 1);
        $this->quota(null, 'turkey', 6);

        $rows = app(QuotaService::class)->listForLease($this->propertyId, $this->leaseId, 2026);

        $this->assertCount(2, $rows, 'one row per species');
        $deer = $rows->firstWhere('species_code', 'whitetail_deer');
        $this->assertSame($this->leaseId, $deer->lease_id, 'lease-specific deer row wins');
        $this->assertSame(2, $deer->max_harvest);
    }
}
