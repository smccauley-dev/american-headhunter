<?php

namespace Tests\Feature\Security;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * security_deposits is system-authored and runtime-read-only.
 *
 * It mirrors invoices/payments/payouts and the Stripe invoice projection: RLS is
 * enabled with a single FOR SELECT policy TO ah_runtime (the two parties + staff)
 * and NO write policy. ah_runtime inherits a table-level DML grant via ALTER
 * DEFAULT PRIVILEGES, so this test proves that grant is inert for writes — RLS
 * default-denies every INSERT/UPDATE — while reads stay scoped to the lessee,
 * the landowner, and staff. Only ah_system (BYPASSRLS — the deposit-charge
 * webhook, release/forfeit jobs, Filament admin) may author these rows.
 *
 * This test connects EXPLICITLY as ah_runtime and proves:
 *
 *   1. RLS is enabled on the table
 *   2. the lessee (payer) may READ their own deposit
 *   3. the landowner (payee) may READ the same deposit
 *   4. an unrelated user may NOT read it (USING scopes it out)
 *   5. staff may READ any deposit
 *   6. the lessee may NOT INSERT a deposit (no write policy → denied)
 *   7. the lessee may NOT UPDATE their own deposit (no write policy → 0 affected)
 *
 * Postgres-only. Skips cleanly when an ah_runtime connection is unavailable.
 */
class SecurityDepositRlsWriteTest extends TestCase
{
    private const RUNTIME = 'billing_rls_write_test';

    private string $payerId;
    private string $payeeId;
    private string $otherUserId;

    protected function setUp(): void
    {
        parent::setUp();

        $base = config('database.connections.billing');
        if (! $base) {
            $this->markTestSkipped('billing connection not configured.');
        }
        config(['database.connections.' . self::RUNTIME => array_merge($base, [
            'username' => env('DB_BILLING_USERNAME', 'ah_runtime'),
            'password' => env('DB_BILLING_PASSWORD', 'secret'),
        ])]);

        try {
            DB::connection(self::RUNTIME)->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('ah_runtime Postgres connection unavailable: ' . $e->getMessage());
        }

        $this->payerId     = (string) Str::uuid();
        $this->payeeId     = (string) Str::uuid();
        $this->otherUserId = (string) Str::uuid();
    }

    protected function tearDown(): void
    {
        // payer/payee ids are cross-DB references (no enforced FK) — clean up
        // explicitly; rows are hard-deleted via the owner connection (bypasses RLS).
        DB::connection('billing')->table('security_deposits')
            ->whereIn('payer_user_id', [$this->payerId, $this->payeeId, $this->otherUserId])
            ->orWhereIn('payee_user_id', [$this->payerId, $this->payeeId, $this->otherUserId])
            ->delete();
        DB::purge(self::RUNTIME);
        parent::tearDown();
    }

    /** Apply an RLS context to the runtime connection's session. */
    private function setContext(string $userId, string $role): void
    {
        $conn = DB::connection(self::RUNTIME);
        $conn->unprepared('SET app.current_user_id = ' . $conn->getPdo()->quote($userId));
        $conn->unprepared('SET app.user_role = ' . $conn->getPdo()->quote($role));
    }

    /** A complete deposit row payload. */
    private function depositRow(): array
    {
        return [
            'id'            => (string) Str::uuid(),
            'lease_id'      => (string) Str::uuid(),
            'payer_user_id' => $this->payerId,
            'payee_user_id' => $this->payeeId,
            'amount_cents'  => 50000,
            'currency'      => 'USD',
            'status'        => 'held',
        ];
    }

    /** Seed a deposit row directly (owner connection, bypasses RLS). */
    private function seedDeposit(): string
    {
        $row = $this->depositRow();
        DB::connection('billing')->table('security_deposits')->insert($row);

        return $row['id'];
    }

    public function test_rls_is_enabled_on_security_deposits(): void
    {
        $row = DB::connection('billing')->selectOne(
            "SELECT relrowsecurity FROM pg_class WHERE relname = 'security_deposits'"
        );
        $this->assertTrue((bool) $row->relrowsecurity, 'RLS must be enabled on security_deposits.');
    }

    public function test_payer_can_read_own_deposit(): void
    {
        $this->seedDeposit();
        $this->setContext($this->payerId, '');

        $this->assertSame(1, DB::connection(self::RUNTIME)->table('security_deposits')
            ->where('payer_user_id', $this->payerId)->count());
    }

    public function test_payee_can_read_the_deposit(): void
    {
        $this->seedDeposit();
        $this->setContext($this->payeeId, '');

        $this->assertSame(1, DB::connection(self::RUNTIME)->table('security_deposits')
            ->where('payee_user_id', $this->payeeId)->count());
    }

    public function test_unrelated_user_cannot_read_the_deposit(): void
    {
        $this->seedDeposit();
        $this->setContext($this->otherUserId, '');

        $this->assertSame(0, DB::connection(self::RUNTIME)->table('security_deposits')
            ->where('payer_user_id', $this->payerId)->count());
    }

    public function test_staff_can_read_any_deposit(): void
    {
        $this->seedDeposit();
        $this->setContext($this->otherUserId, 'staff');

        $this->assertSame(1, DB::connection(self::RUNTIME)->table('security_deposits')
            ->where('payer_user_id', $this->payerId)->count());
    }

    public function test_runtime_cannot_insert_deposit(): void
    {
        $this->setContext($this->payerId, '');

        $this->expectException(QueryException::class);

        // No write policy exists — RLS default-denies the INSERT even for own row.
        DB::connection(self::RUNTIME)->table('security_deposits')->insert($this->depositRow());
    }

    public function test_runtime_cannot_update_deposit(): void
    {
        $id = $this->seedDeposit();
        $this->setContext($this->payerId, '');

        // No UPDATE/ALL policy — the row is invisible to UPDATE, so nothing is
        // affected (system-authored: a party can never mutate their deposit).
        $affected = DB::connection(self::RUNTIME)->table('security_deposits')
            ->where('id', $id)->update(['status' => 'refunded']);

        $this->assertSame(0, $affected, 'A party must not be able to mutate their security deposit.');
    }
}
