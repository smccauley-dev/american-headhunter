<?php

namespace Tests\Feature\Wildlife;

use App\Models\Wildlife\HarvestLog;
use App\Services\Wildlife\HarvestService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * HarvestService — the field-operation write path and the DB-5 authorization
 * boundary. DB 5 has no RLS, so the service standing check is the whole fence;
 * these tests exercise it directly plus quota enforcement and offline dedup.
 *
 * Real rows on the lease + wildlife connections (tests run as owner → RLS
 * bypassed). No DatabaseTransactions — the updated_at trigger uses NOW(), frozen
 * for a transaction's life, so rows are cleaned in tearDown.
 */
class WildlifeServiceTest extends TestCase
{
    private string $leaseId;

    private string $applicationId;

    private string $propertyId;

    private string $lesseeId;

    private string $lessorId;

    private string $hunterId;

    private string $strangerId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->applicationId = (string) Str::uuid();
        $this->leaseId = (string) Str::uuid();
        $this->propertyId = (string) Str::uuid();
        $this->lesseeId = (string) Str::uuid();
        $this->lessorId = (string) Str::uuid();
        $this->hunterId = (string) Str::uuid();
        $this->strangerId = (string) Str::uuid();

        DB::connection('lease')->table('lease_applications')->insert([
            'id' => $this->applicationId,
            'listing_id' => (string) Str::uuid(),
            'applicant_user_id' => $this->lesseeId,
            'application_type' => 'individual',
            'status' => 'approved',
        ]);

        DB::connection('lease')->table('leases')->insert([
            'id' => $this->leaseId,
            'application_id' => $this->applicationId,
            'property_id' => $this->propertyId,
            'listing_id' => (string) Str::uuid(),
            'lessee_user_id' => $this->lesseeId,
            'lessor_user_id' => $this->lessorId,
            'status' => 'active',
            'start_date' => '2026-10-01',
            'end_date' => '2026-11-30',
            'total_price' => '2500.00',
            'deposit_paid' => '0.00',
        ]);

        DB::connection('lease')->table('lease_hunters')->insert([
            'id' => (string) Str::uuid(),
            'lease_id' => $this->leaseId,
            'user_id' => $this->hunterId,
            'role' => 'member',
            'is_approved' => true,
            'approved_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        DB::connection('wildlife')->table('harvest_logs')->where('lease_id', $this->leaseId)->delete();
        DB::connection('wildlife')->table('harvest_quotas')->where('property_id', $this->propertyId)->delete();
        DB::connection('lease')->table('lease_hunters')->where('lease_id', $this->leaseId)->delete();
        DB::connection('lease')->table('leases')->where('id', $this->leaseId)->delete();
        DB::connection('lease')->table('lease_applications')->where('id', $this->applicationId)->delete();

        parent::tearDown();
    }

    private function service(): HarvestService
    {
        return app(HarvestService::class);
    }

    /** @return array<string,mixed> */
    private function harvestData(array $overrides = []): array
    {
        return array_merge([
            'species_code' => 'whitetail_deer',
            'harvest_date' => '2026-11-15',
            'weapon_type' => 'bow',
        ], $overrides);
    }

    public function test_lessee_can_log_a_harvest(): void
    {
        $harvest = $this->service()->log($this->lesseeId, $this->leaseId, $this->harvestData());

        $this->assertInstanceOf(HarvestLog::class, $harvest);
        $this->assertSame($this->lesseeId, $harvest->user_id);
        $this->assertSame($this->propertyId, $harvest->property_id);
    }

    public function test_approved_hunter_on_the_lease_can_log_a_harvest(): void
    {
        $harvest = $this->service()->log($this->hunterId, $this->leaseId, $this->harvestData());

        $this->assertSame($this->hunterId, $harvest->user_id);
    }

    public function test_stranger_without_standing_is_denied(): void
    {
        try {
            $this->service()->log($this->strangerId, $this->leaseId, $this->harvestData());
            $this->fail('a user with no standing must not be able to log a harvest');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_full_quota_rejects_a_harvest_with_409(): void
    {
        DB::connection('wildlife')->table('harvest_quotas')->insert([
            'id' => (string) Str::uuid(),
            'property_id' => $this->propertyId,
            'lease_id' => $this->leaseId,
            'species_code' => 'whitetail_deer',
            'season_year' => 2026,
            'max_harvest' => 1,
            'current_harvest' => 0,
        ]);

        // First tag is available.
        $this->service()->log($this->lesseeId, $this->leaseId, $this->harvestData());

        // Second must be rejected — quota full.
        try {
            $this->service()->log($this->lesseeId, $this->leaseId, $this->harvestData());
            $this->fail('a full quota must reject the harvest');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        $this->assertSame(1, DB::connection('wildlife')->table('harvest_logs')->where('lease_id', $this->leaseId)->count());
        $current = DB::connection('wildlife')->table('harvest_quotas')
            ->where('property_id', $this->propertyId)->value('current_harvest');
        $this->assertSame(1, (int) $current, 'a rejected harvest must not inflate the count');
    }

    public function test_offline_replay_is_idempotent_and_consumes_one_tag(): void
    {
        DB::connection('wildlife')->table('harvest_quotas')->insert([
            'id' => (string) Str::uuid(),
            'property_id' => $this->propertyId,
            'lease_id' => $this->leaseId,
            'species_code' => 'whitetail_deer',
            'season_year' => 2026,
            'max_harvest' => 5,
            'current_harvest' => 0,
        ]);

        $localId = (string) Str::uuid();

        $first = $this->service()->log($this->lesseeId, $this->leaseId, $this->harvestData(['local_record_id' => $localId]));
        $second = $this->service()->log($this->lesseeId, $this->leaseId, $this->harvestData(['local_record_id' => $localId]));

        $this->assertSame($first->id, $second->id, 'a replayed local_record_id returns the same row');
        $this->assertSame(1, DB::connection('wildlife')->table('harvest_logs')->where('lease_id', $this->leaseId)->count());

        $current = DB::connection('wildlife')->table('harvest_quotas')
            ->where('property_id', $this->propertyId)->value('current_harvest');
        $this->assertSame(1, (int) $current, 'a replay must not double-count the quota');
    }

    public function test_find_for_user_denies_an_unrelated_reader_with_404(): void
    {
        $harvest = $this->service()->log($this->lesseeId, $this->leaseId, $this->harvestData());

        // Owner reads fine.
        $this->assertSame($harvest->id, $this->service()->findForUser($this->lesseeId, $harvest->id)->id);

        // A stranger cannot — existence is not disclosed (404, not 403).
        try {
            $this->service()->findForUser($this->strangerId, $harvest->id);
            $this->fail('an unrelated reader must not see the harvest');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }
}
