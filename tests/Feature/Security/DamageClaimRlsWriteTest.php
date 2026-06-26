<?php

namespace Tests\Feature\Security;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * damage_claims is system-authored and runtime-read-only (SEC-045).
 *
 * RLS is enabled with a single FOR SELECT policy TO ah_runtime (the claimant + staff)
 * and NO write policy, so the inherited DML grant is inert for writes — every
 * INSERT/UPDATE default-denies — while reads stay scoped to the claimant and staff.
 * Only ah_system (BYPASSRLS — the db.system file route and the Filament admin review)
 * may author these rows.
 *
 * Connects EXPLICITLY as ah_runtime. Postgres-only; skips when unavailable.
 */
class DamageClaimRlsWriteTest extends TestCase
{
    private const RUNTIME = 'incidents_claims_rls_write_test';

    private string $claimantId;
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

        $this->claimantId  = (string) Str::uuid();
        $this->otherUserId = (string) Str::uuid();
    }

    protected function tearDown(): void
    {
        DB::connection('incidents')->table('damage_claims')
            ->whereIn('claimant_user_id', [$this->claimantId, $this->otherUserId])
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

    private function claimRow(): array
    {
        return [
            'id'                   => (string) Str::uuid(),
            'lease_id'             => (string) Str::uuid(),
            'claimant_user_id'     => $this->claimantId,
            'claim_type'           => 'property_damage',
            'status'               => 'submitted',
            'description'          => 'Broken window.',
            'amount_claimed_cents' => 25000,
        ];
    }

    private function seedClaim(): string
    {
        $row = $this->claimRow();
        DB::connection('incidents')->table('damage_claims')->insert($row);

        return $row['id'];
    }

    public function test_rls_is_enabled_on_damage_claims(): void
    {
        $row = DB::connection('incidents')->selectOne(
            "SELECT relrowsecurity FROM pg_class WHERE relname = 'damage_claims'"
        );
        $this->assertTrue((bool) $row->relrowsecurity, 'RLS must be enabled on damage_claims.');
    }

    public function test_claimant_can_read_own_claim(): void
    {
        $this->seedClaim();
        $this->setContext($this->claimantId, '');

        $this->assertSame(1, DB::connection(self::RUNTIME)->table('damage_claims')
            ->where('claimant_user_id', $this->claimantId)->count());
    }

    public function test_unrelated_user_cannot_read_the_claim(): void
    {
        $this->seedClaim();
        $this->setContext($this->otherUserId, '');

        $this->assertSame(0, DB::connection(self::RUNTIME)->table('damage_claims')
            ->where('claimant_user_id', $this->claimantId)->count());
    }

    public function test_staff_can_read_any_claim(): void
    {
        $this->seedClaim();
        $this->setContext($this->otherUserId, 'staff');

        $this->assertSame(1, DB::connection(self::RUNTIME)->table('damage_claims')
            ->where('claimant_user_id', $this->claimantId)->count());
    }

    public function test_runtime_cannot_insert_claim(): void
    {
        $this->setContext($this->claimantId, '');

        $this->expectException(QueryException::class);

        DB::connection(self::RUNTIME)->table('damage_claims')->insert($this->claimRow());
    }

    public function test_runtime_cannot_update_claim(): void
    {
        $id = $this->seedClaim();
        $this->setContext($this->claimantId, '');

        $affected = DB::connection(self::RUNTIME)->table('damage_claims')
            ->where('id', $id)->update(['status' => 'approved']);

        $this->assertSame(0, $affected, 'A claimant must not be able to mutate their claim.');
    }
}
