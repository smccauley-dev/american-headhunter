<?php

namespace Tests\Feature\Security;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * lease_payments is system-authored and runtime-read-only.
 *
 * Mirrors security_deposits / payouts / the Stripe invoice projection: RLS is
 * enabled with a single FOR SELECT policy TO ah_runtime (the two parties + staff)
 * and NO write policy. ah_runtime inherits a table-level DML grant via ALTER
 * DEFAULT PRIVILEGES, so this test proves that grant is inert for writes — RLS
 * default-denies every INSERT/UPDATE — while reads stay scoped to the payer
 * (lessee), the payee (landowner), and staff. Only ah_system (BYPASSRLS — the
 * destination-charge webhook, the db.system success-return, Filament admin) may
 * author these rows.
 *
 * This test connects EXPLICITLY as ah_runtime and proves:
 *
 *   1. RLS is enabled on the table
 *   2. the payer (lessee) may READ their own payment
 *   3. the payee (landowner) may READ the same payment
 *   4. an unrelated user may NOT read it (USING scopes it out)
 *   5. staff may READ any payment
 *   6. the payer may NOT INSERT a payment (no write policy → denied)
 *   7. the payer may NOT UPDATE their own payment (no write policy → 0 affected)
 *
 * Postgres-only. Skips cleanly when an ah_runtime connection is unavailable.
 */
class LeasePaymentRlsWriteTest extends TestCase
{
    private const RUNTIME = 'billing_lease_payment_rls_test';

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
        DB::connection('billing')->table('lease_payments')
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

    /** A complete lease-payment row payload. */
    private function paymentRow(): array
    {
        return [
            'id'                       => (string) Str::uuid(),
            'lease_id'                 => (string) Str::uuid(),
            'payer_user_id'            => $this->payerId,
            'payee_user_id'            => $this->payeeId,
            'stripe_account_id'        => 'acct_rls_' . Str::random(8),
            'gross_cents'              => 100300,
            'surcharge_cents'          => 300,
            'application_fee_cents'    => 5300,
            'net_cents'                => 95000,
            'currency'                 => 'USD',
            'status'                   => 'collected',
            'stripe_payment_intent_id' => 'pi_rls_' . Str::random(12),
        ];
    }

    /** Seed a payment row directly (owner connection, bypasses RLS). */
    private function seedPayment(): string
    {
        $row = $this->paymentRow();
        DB::connection('billing')->table('lease_payments')->insert($row);

        return $row['id'];
    }

    public function test_rls_is_enabled_on_lease_payments(): void
    {
        $row = DB::connection('billing')->selectOne(
            "SELECT relrowsecurity FROM pg_class WHERE relname = 'lease_payments'"
        );
        $this->assertTrue((bool) $row->relrowsecurity, 'RLS must be enabled on lease_payments.');
    }

    public function test_payer_can_read_own_payment(): void
    {
        $this->seedPayment();
        $this->setContext($this->payerId, '');

        $this->assertSame(1, DB::connection(self::RUNTIME)->table('lease_payments')
            ->where('payer_user_id', $this->payerId)->count());
    }

    public function test_payee_can_read_the_payment(): void
    {
        $this->seedPayment();
        $this->setContext($this->payeeId, '');

        $this->assertSame(1, DB::connection(self::RUNTIME)->table('lease_payments')
            ->where('payee_user_id', $this->payeeId)->count());
    }

    public function test_unrelated_user_cannot_read_the_payment(): void
    {
        $this->seedPayment();
        $this->setContext($this->otherUserId, '');

        $this->assertSame(0, DB::connection(self::RUNTIME)->table('lease_payments')
            ->where('payer_user_id', $this->payerId)->count());
    }

    public function test_staff_can_read_any_payment(): void
    {
        $this->seedPayment();
        $this->setContext($this->otherUserId, 'staff');

        $this->assertSame(1, DB::connection(self::RUNTIME)->table('lease_payments')
            ->where('payer_user_id', $this->payerId)->count());
    }

    public function test_runtime_cannot_insert_payment(): void
    {
        $this->setContext($this->payerId, '');

        $this->expectException(QueryException::class);

        // No write policy exists — RLS default-denies the INSERT even for own row.
        DB::connection(self::RUNTIME)->table('lease_payments')->insert($this->paymentRow());
    }

    public function test_runtime_cannot_update_payment(): void
    {
        $id = $this->seedPayment();
        $this->setContext($this->payerId, '');

        // No UPDATE/ALL policy — the row is invisible to UPDATE, so nothing is
        // affected (system-authored: a party can never mutate their payment).
        $affected = DB::connection(self::RUNTIME)->table('lease_payments')
            ->where('id', $id)->update(['status' => 'refunded']);

        $this->assertSame(0, $affected, 'A party must not be able to mutate their lease payment.');
    }
}
