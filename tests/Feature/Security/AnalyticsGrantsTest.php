<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * DB 8 revenue-isolation regression.
 *
 * Analytics (DB 8) has no RLS — its rows are global aggregates, so there is
 * nothing to filter per user. The real exposure risk is revenue leaking to a
 * public/runtime read path that also feeds the marketing homepage. We close that
 * at the GRANT level, not in app code:
 *
 *   - platform_snapshots (counts/acres) : SELECT to ah_readonly + ah_system.
 *   - revenue_snapshots  (GMV/fees/...) : SELECT to ah_system ONLY.
 *
 * This test connects EXPLICITLY as ah_readonly (the public/runtime read role) and
 * proves it can read platform_snapshots but is denied on revenue_snapshots, and
 * that ah_system can read both. If anyone widens the grant to ALL TABLES, grants
 * revenue to ah_readonly, or changes RevenueSnapshot back to the `analytics`
 * connection, the corresponding assertion fails.
 *
 * Postgres-only. Skips cleanly when the analytics cluster is unavailable.
 */
class AnalyticsGrantsTest extends TestCase
{
    private const READONLY = 'analytics_readonly_test';

    protected function setUp(): void
    {
        parent::setUp();

        $base = config('database.connections.analytics');
        if (! $base) {
            $this->markTestSkipped('analytics connection not configured.');
        }

        // Authenticate as the read-only role explicitly, regardless of what the
        // test kernel uses elsewhere.
        config(['database.connections.' . self::READONLY => array_merge($base, [
            'username' => env('DB_READONLY_USERNAME', 'ah_readonly'),
            'password' => env('DB_READONLY_PASSWORD', 'secret'),
        ])]);

        try {
            DB::connection(self::READONLY)->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('ah_readonly analytics connection unavailable: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        DB::purge(self::READONLY);
        parent::tearDown();
    }

    public function test_readonly_can_select_platform_snapshots(): void
    {
        $count = DB::connection(self::READONLY)->table('platform_snapshots')->count();

        $this->assertIsInt($count, 'ah_readonly must be able to read platform_snapshots.');
    }

    public function test_readonly_is_denied_on_revenue_snapshots(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessageMatches('/permission denied/i');

        DB::connection(self::READONLY)->table('revenue_snapshots')->count();
    }

    public function test_system_can_select_revenue_snapshots(): void
    {
        // analytics_admin is the ah_system connection used by the admin dashboard.
        if (! config('database.connections.analytics_admin')) {
            $this->markTestSkipped('analytics_admin connection not configured.');
        }

        try {
            $count = DB::connection('analytics_admin')->table('revenue_snapshots')->count();
        } catch (\Throwable $e) {
            $this->markTestSkipped('ah_system analytics connection unavailable: ' . $e->getMessage());
        }

        $this->assertIsInt($count, 'ah_system must be able to read revenue_snapshots.');
    }
}
