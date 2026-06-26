<?php

namespace Tests\Feature\Security;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * lease_disputes is system-authored and runtime-read-only (SEC-045).
 *
 * RLS is enabled with a single FOR SELECT policy TO ah_runtime (the two parties +
 * staff) and NO write policy. ah_runtime inherits a table-level DML grant via ALTER
 * DEFAULT PRIVILEGES, so this test proves that grant is inert for writes — RLS
 * default-denies every INSERT/UPDATE — while reads stay scoped to the initiator, the
 * respondent, and staff. Only ah_system (BYPASSRLS — the db.system contest route and
 * the Filament admin panel) may author these rows.
 *
 * Connects EXPLICITLY as ah_runtime. Postgres-only; skips when unavailable.
 */
class LeaseDisputeRlsWriteTest extends TestCase
{
    private const RUNTIME = 'incidents_rls_write_test';

    private string $initiatorId;
    private string $respondentId;
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

        $this->initiatorId  = (string) Str::uuid();
        $this->respondentId = (string) Str::uuid();
        $this->otherUserId  = (string) Str::uuid();
    }

    protected function tearDown(): void
    {
        DB::connection('incidents')->table('lease_disputes')
            ->whereIn('initiator_user_id', [$this->initiatorId, $this->respondentId, $this->otherUserId])
            ->orWhereIn('respondent_user_id', [$this->initiatorId, $this->respondentId, $this->otherUserId])
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

    private function disputeRow(): array
    {
        return [
            'id'                 => (string) Str::uuid(),
            'lease_id'           => (string) Str::uuid(),
            'initiator_user_id'  => $this->initiatorId,
            'respondent_user_id' => $this->respondentId,
            'dispute_type'       => 'damage',
            'status'             => 'open',
            'description'        => 'Contesting the forfeiture.',
        ];
    }

    private function seedDispute(): string
    {
        $row = $this->disputeRow();
        DB::connection('incidents')->table('lease_disputes')->insert($row);

        return $row['id'];
    }

    public function test_rls_is_enabled_on_lease_disputes(): void
    {
        $row = DB::connection('incidents')->selectOne(
            "SELECT relrowsecurity FROM pg_class WHERE relname = 'lease_disputes'"
        );
        $this->assertTrue((bool) $row->relrowsecurity, 'RLS must be enabled on lease_disputes.');
    }

    public function test_initiator_can_read_own_dispute(): void
    {
        $this->seedDispute();
        $this->setContext($this->initiatorId, '');

        $this->assertSame(1, DB::connection(self::RUNTIME)->table('lease_disputes')
            ->where('initiator_user_id', $this->initiatorId)->count());
    }

    public function test_respondent_can_read_the_dispute(): void
    {
        $this->seedDispute();
        $this->setContext($this->respondentId, '');

        $this->assertSame(1, DB::connection(self::RUNTIME)->table('lease_disputes')
            ->where('respondent_user_id', $this->respondentId)->count());
    }

    public function test_unrelated_user_cannot_read_the_dispute(): void
    {
        $this->seedDispute();
        $this->setContext($this->otherUserId, '');

        $this->assertSame(0, DB::connection(self::RUNTIME)->table('lease_disputes')
            ->where('initiator_user_id', $this->initiatorId)->count());
    }

    public function test_staff_can_read_any_dispute(): void
    {
        $this->seedDispute();
        $this->setContext($this->otherUserId, 'staff');

        $this->assertSame(1, DB::connection(self::RUNTIME)->table('lease_disputes')
            ->where('initiator_user_id', $this->initiatorId)->count());
    }

    public function test_runtime_cannot_insert_dispute(): void
    {
        $this->setContext($this->initiatorId, '');

        $this->expectException(QueryException::class);

        DB::connection(self::RUNTIME)->table('lease_disputes')->insert($this->disputeRow());
    }

    public function test_runtime_cannot_update_dispute(): void
    {
        $id = $this->seedDispute();
        $this->setContext($this->initiatorId, '');

        $affected = DB::connection(self::RUNTIME)->table('lease_disputes')
            ->where('id', $id)->update(['status' => 'resolved']);

        $this->assertSame(0, $affected, 'A party must not be able to mutate their dispute.');
    }
}
