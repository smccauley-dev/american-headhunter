<?php

namespace Tests\Feature\Analytics;

use App\Jobs\Etl\SyncPlatformSnapshot;
use App\Models\Analytics\PlatformSnapshot;
use App\Models\Analytics\RevenueSnapshot;
use App\Services\Analytics\AnalyticsService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Read-path contract for the DB 8 analytics rollups: the ETL writes both tables,
 * the service surfaces counts to public/admin and revenue only via the restricted
 * connection, and publicStats() never carries revenue.
 *
 * Integration-style — runs the real ETL against the live cluster (skips if it is
 * unavailable). The append-only snapshot rows it creates are harmless history.
 */
class AnalyticsServiceTest extends TestCase
{
    private AnalyticsService $service;

    protected function setUp(): void
    {
        parent::setUp();

        try {
            DB::connection('analytics')->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('analytics connection unavailable: ' . $e->getMessage());
        }

        $this->service = new AnalyticsService;
    }

    public function test_etl_writes_a_matched_snapshot_pair(): void
    {
        (new SyncPlatformSnapshot)->handle();

        $platform = PlatformSnapshot::query()->orderByDesc('captured_at')->first();
        $revenue  = RevenueSnapshot::query()->orderByDesc('captured_at')->first();

        $this->assertNotNull($platform);
        $this->assertNotNull($revenue);
        // Both tables are written in one run sharing a single captured_at.
        $this->assertEquals(
            $platform->captured_at->timestamp,
            $revenue->captured_at->timestamp,
            'platform and revenue snapshots must share a captured_at.'
        );
    }

    public function test_public_stats_carry_no_revenue(): void
    {
        (new SyncPlatformSnapshot)->handle();

        $stats = $this->service->publicStats();

        $this->assertSame(
            ['total_users', 'total_leases', 'total_acres'],
            array_keys($stats),
            'publicStats must expose only counts/acres — never revenue.'
        );
        foreach ($stats as $value) {
            $this->assertIsNumeric($value);
        }
    }

    public function test_dashboard_counts_have_no_revenue_keys(): void
    {
        (new SyncPlatformSnapshot)->handle();

        $counts = array_keys($this->service->dashboardCounts());

        foreach (['gmv_cents', 'platform_fees_cents', 'payouts_cents'] as $revenueKey) {
            $this->assertNotContains($revenueKey, $counts);
        }
        $this->assertContains('users_by_type', $counts);
        $this->assertContains('total_acres', $counts);
    }

    public function test_revenue_reads_through_the_restricted_connection(): void
    {
        (new SyncPlatformSnapshot)->handle();

        $revenue = $this->service->revenue();

        $this->assertInstanceOf(RevenueSnapshot::class, $revenue);
        // The model is pinned to the ah_system path; ah_readonly cannot read it.
        $this->assertSame('analytics_admin', $revenue->getConnectionName());
    }

    public function test_readonly_model_blocks_writes(): void
    {
        (new SyncPlatformSnapshot)->handle();

        $snapshot = PlatformSnapshot::query()->first();

        $this->expectException(\LogicException::class);
        $snapshot->total_users = 0;
        $snapshot->save();
    }
}
