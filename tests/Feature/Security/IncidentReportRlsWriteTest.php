<?php

namespace Tests\Feature\Security;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * incident_reports is system-authored and runtime-read-only (SEC-045).
 *
 * RLS is enabled with a single FOR SELECT policy TO ah_runtime (the reporter + staff)
 * and NO write policy, so the inherited DML grant is inert for writes — every
 * INSERT/UPDATE default-denies — while reads stay scoped to the reporter and staff.
 * Only ah_system (BYPASSRLS — the db.system report route and the Filament admin
 * triage) may author these rows.
 *
 * Connects EXPLICITLY as ah_runtime. Postgres-only; skips when unavailable.
 */
class IncidentReportRlsWriteTest extends TestCase
{
    private const RUNTIME = 'incidents_reports_rls_write_test';

    private string $reporterId;
    private string $otherUserId;

    protected function setUp(): void
    {
        parent::setUp();

        $base = config('database.connections.incidents');
        if (! $base) {
            $this->markTestSkipped('incidents connection not configured.');
        }
        config(['database.connections.' . self::RUNTIME => array_merge($base, [
            'username' => env('DB_INCIDENTS_USERNAME', 'ah_runtime'),
            'password' => env('DB_INCIDENTS_PASSWORD', 'secret'),
        ])]);

        try {
            DB::connection(self::RUNTIME)->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('ah_runtime Postgres connection unavailable: ' . $e->getMessage());
        }

        $this->reporterId  = (string) Str::uuid();
        $this->otherUserId = (string) Str::uuid();
    }

    protected function tearDown(): void
    {
        DB::connection('incidents')->table('incident_reports')
            ->whereIn('reporter_user_id', [$this->reporterId, $this->otherUserId])
            ->delete();
        DB::purge(self::RUNTIME);
        parent::tearDown();
    }

    private function setContext(string $userId, string $role): void
    {
        $conn = DB::connection(self::RUNTIME);
        $conn->unprepared('SET app.current_user_id = ' . $conn->getPdo()->quote($userId));
        $conn->unprepared('SET app.user_role = ' . $conn->getPdo()->quote($role));
    }

    private function reportRow(): array
    {
        return [
            'id'               => (string) Str::uuid(),
            'property_id'      => (string) Str::uuid(),
            'reporter_user_id' => $this->reporterId,
            'incident_type'    => 'trespassing',
            'severity'         => 'moderate',
            'status'           => 'open',
            'occurred_at'      => now(),
            'description'      => 'Unknown vehicle on the access road.',
        ];
    }

    private function seedReport(): string
    {
        $row = $this->reportRow();
        DB::connection('incidents')->table('incident_reports')->insert($row);

        return $row['id'];
    }

    public function test_rls_is_enabled_on_incident_reports(): void
    {
        $row = DB::connection('incidents')->selectOne(
            "SELECT relrowsecurity FROM pg_class WHERE relname = 'incident_reports'"
        );
        $this->assertTrue((bool) $row->relrowsecurity, 'RLS must be enabled on incident_reports.');
    }

    public function test_reporter_can_read_own_report(): void
    {
        $this->seedReport();
        $this->setContext($this->reporterId, '');

        $this->assertSame(1, DB::connection(self::RUNTIME)->table('incident_reports')
            ->where('reporter_user_id', $this->reporterId)->count());
    }

    public function test_unrelated_user_cannot_read_the_report(): void
    {
        $this->seedReport();
        $this->setContext($this->otherUserId, '');

        $this->assertSame(0, DB::connection(self::RUNTIME)->table('incident_reports')
            ->where('reporter_user_id', $this->reporterId)->count());
    }

    public function test_staff_can_read_any_report(): void
    {
        $this->seedReport();
        $this->setContext($this->otherUserId, 'staff');

        $this->assertSame(1, DB::connection(self::RUNTIME)->table('incident_reports')
            ->where('reporter_user_id', $this->reporterId)->count());
    }

    public function test_runtime_cannot_insert_report(): void
    {
        $this->setContext($this->reporterId, '');

        $this->expectException(QueryException::class);

        DB::connection(self::RUNTIME)->table('incident_reports')->insert($this->reportRow());
    }

    public function test_runtime_cannot_update_report(): void
    {
        $id = $this->seedReport();
        $this->setContext($this->reporterId, '');

        $affected = DB::connection(self::RUNTIME)->table('incident_reports')
            ->where('id', $id)->update(['status' => 'closed']);

        $this->assertSame(0, $affected, 'A reporter must not be able to mutate their report.');
    }
}
