<?php

namespace Tests\Feature\Security;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * incident_admin_notes is system-authored and admin-read-only (SEC-045).
 *
 * Unlike incident_reports — whose SELECT policy lets the reporter read their own row —
 * the investigation-notes policy is gated to staff/super_admin ONLY. The reporter
 * shares the parent incident but can never read its notes. There is NO write policy,
 * so the inherited DML grant is inert for writes; only ah_system (the Filament admin
 * panel, BYPASSRLS) authors these rows.
 *
 * Connects EXPLICITLY as ah_runtime. Postgres-only; skips when unavailable.
 */
class IncidentAdminNoteRlsWriteTest extends TestCase
{
    private const RUNTIME = 'incident_admin_notes_rls_write_test';

    private string $reporterId;
    private string $staffId;
    private string $reportId;

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

        $this->reporterId = (string) Str::uuid();
        $this->staffId    = (string) Str::uuid();

        $this->reportId = (string) Str::uuid();
        DB::connection('incidents')->table('incident_reports')->insert([
            'id'               => $this->reportId,
            'property_id'      => (string) Str::uuid(),
            'reporter_user_id' => $this->reporterId,
            'incident_type'    => 'trespassing',
            'severity'         => 'moderate',
            'status'           => 'open',
            'occurred_at'      => now(),
            'description'      => 'Unknown vehicle on the access road.',
        ]);
    }

    protected function tearDown(): void
    {
        // Cascades to incident_admin_notes via the FK.
        DB::connection('incidents')->table('incident_reports')->where('id', $this->reportId)->delete();
        DB::purge(self::RUNTIME);
        parent::tearDown();
    }

    private function setContext(string $userId, string $role): void
    {
        $conn = DB::connection(self::RUNTIME);
        $conn->unprepared('SET app.current_user_id = ' . $conn->getPdo()->quote($userId));
        $conn->unprepared('SET app.user_role = ' . $conn->getPdo()->quote($role));
    }

    private function noteRow(): array
    {
        return [
            'id'                 => (string) Str::uuid(),
            'incident_report_id' => $this->reportId,
            'author_user_id'     => $this->staffId,
            'note'               => 'Called the sheriff; awaiting callback.',
        ];
    }

    private function seedNote(): string
    {
        $row = $this->noteRow();
        DB::connection('incidents')->table('incident_admin_notes')->insert($row);

        return $row['id'];
    }

    public function test_rls_is_enabled_on_incident_admin_notes(): void
    {
        $row = DB::connection('incidents')->selectOne(
            "SELECT relrowsecurity FROM pg_class WHERE relname = 'incident_admin_notes'"
        );
        $this->assertTrue((bool) $row->relrowsecurity, 'RLS must be enabled on incident_admin_notes.');
    }

    public function test_staff_can_read_notes(): void
    {
        $this->seedNote();
        $this->setContext($this->staffId, 'staff');

        $this->assertSame(1, DB::connection(self::RUNTIME)->table('incident_admin_notes')
            ->where('incident_report_id', $this->reportId)->count());
    }

    public function test_reporter_cannot_read_notes_on_their_own_incident(): void
    {
        $this->seedNote();
        // The reporter owns the parent report but is NOT staff — notes stay hidden.
        $this->setContext($this->reporterId, '');

        $this->assertSame(0, DB::connection(self::RUNTIME)->table('incident_admin_notes')
            ->where('incident_report_id', $this->reportId)->count());
    }

    public function test_runtime_cannot_insert_a_note(): void
    {
        $this->setContext($this->staffId, 'staff');

        $this->expectException(QueryException::class);

        DB::connection(self::RUNTIME)->table('incident_admin_notes')->insert($this->noteRow());
    }
}
