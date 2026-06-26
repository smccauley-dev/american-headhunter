<?php

namespace Tests\Feature\Security;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * stripe_accounts is system-authored and runtime-read-only (SEC-045).
 *
 * The Connect account row — and its charges_enabled / payouts_enabled flags that
 * gate receiving money — is authored only by ah_system (the onboarding flow and
 * the account.updated webhook). RLS is enabled with a single FOR SELECT policy TO
 * ah_runtime (own row + staff) and NO write policy; ah_runtime inherits a blanket
 * DML grant, so this test proves that grant is inert for writes while reads stay
 * scoped to the owner and staff.
 *
 * Connects EXPLICITLY as ah_runtime and proves:
 *   1. RLS is enabled on the table
 *   2. the owner may READ their own account
 *   3. an unrelated user may NOT read it
 *   4. staff may READ any account
 *   5. a user may NOT INSERT an account (no write policy → denied)
 *   6. a user may NOT UPDATE their own account (no write policy → 0 affected)
 *
 * Postgres-only. Skips cleanly when an ah_runtime connection is unavailable.
 */
class StripeAccountRlsWriteTest extends TestCase
{
    private const RUNTIME = 'billing_rls_write_test';

    private string $ownerId;
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

        $this->ownerId     = (string) Str::uuid();
        $this->otherUserId = (string) Str::uuid();
    }

    protected function tearDown(): void
    {
        DB::connection('billing')->table('stripe_accounts')
            ->whereIn('user_id', [$this->ownerId, $this->otherUserId])
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

    private function accountRow(?string $userId = null): array
    {
        return [
            'id'                => (string) Str::uuid(),
            'user_id'           => $userId ?? $this->ownerId,
            'stripe_account_id' => 'acct_test_' . Str::random(12),
            'charges_enabled'   => false,
            'payouts_enabled'   => false,
            'details_submitted' => false,
        ];
    }

    private function seedAccount(): string
    {
        $row = $this->accountRow();
        DB::connection('billing')->table('stripe_accounts')->insert($row);

        return $row['id'];
    }

    public function test_rls_is_enabled_on_stripe_accounts(): void
    {
        $row = DB::connection('billing')->selectOne(
            "SELECT relrowsecurity FROM pg_class WHERE relname = 'stripe_accounts'"
        );
        $this->assertTrue((bool) $row->relrowsecurity, 'RLS must be enabled on stripe_accounts.');
    }

    public function test_owner_can_read_own_account(): void
    {
        $this->seedAccount();
        $this->setContext($this->ownerId, '');

        $this->assertSame(1, DB::connection(self::RUNTIME)->table('stripe_accounts')
            ->where('user_id', $this->ownerId)->count());
    }

    public function test_unrelated_user_cannot_read_the_account(): void
    {
        $this->seedAccount();
        $this->setContext($this->otherUserId, '');

        $this->assertSame(0, DB::connection(self::RUNTIME)->table('stripe_accounts')
            ->where('user_id', $this->ownerId)->count());
    }

    public function test_staff_can_read_any_account(): void
    {
        $this->seedAccount();
        $this->setContext($this->otherUserId, 'staff');

        $this->assertSame(1, DB::connection(self::RUNTIME)->table('stripe_accounts')
            ->where('user_id', $this->ownerId)->count());
    }

    public function test_runtime_cannot_insert_account(): void
    {
        $this->setContext($this->ownerId, '');

        $this->expectException(QueryException::class);

        // No write policy exists — RLS default-denies the INSERT even for own row.
        DB::connection(self::RUNTIME)->table('stripe_accounts')->insert($this->accountRow());
    }

    public function test_runtime_cannot_flip_own_payouts_enabled(): void
    {
        $id = $this->seedAccount();
        $this->setContext($this->ownerId, '');

        // No UPDATE/ALL policy — the row is invisible to UPDATE, so a landowner can
        // never forge their own payouts_enabled flag.
        $affected = DB::connection(self::RUNTIME)->table('stripe_accounts')
            ->where('id', $id)->update(['payouts_enabled' => true]);

        $this->assertSame(0, $affected, 'A user must not be able to mutate their Connect account flags.');
    }
}
