<?php

namespace Tests\Feature\Security;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 5.7 constraint 1 — the Stripe invoice projection is system-authored and
 * runtime-read-only.
 *
 * The projection mirrors invoices/payments/payouts: RLS is enabled with a single
 * FOR SELECT policy TO ah_runtime and NO write policy. ah_runtime inherits a
 * table-level DML grant via ALTER DEFAULT PRIVILEGES, so this test proves that
 * the grant is inert for writes — RLS default-denies every INSERT/UPDATE — while
 * reads stay correctly scoped to the subscriber + staff. Only ah_system
 * (BYPASSRLS — the webhook worker + reconcile job) may author these rows.
 *
 * This test connects EXPLICITLY as ah_runtime and proves:
 *
 *   1. RLS is enabled on the table
 *   2. a subscriber may READ their own projected invoice
 *   3. a subscriber may NOT read another user's invoice (USING scopes it out)
 *   4. staff may READ any invoice
 *   5. a subscriber may NOT INSERT a projection row (no write policy → denied)
 *   6. a subscriber may NOT UPDATE their own row (no write policy → 0 affected)
 *
 * Postgres-only. Skips cleanly when an ah_runtime connection is unavailable.
 */
class StripeInvoiceProjectionRlsWriteTest extends TestCase
{
    private const RUNTIME = 'billing_rls_write_test';

    private string $subscriberId;
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

        $this->subscriberId = (string) Str::uuid();
        $this->otherUserId  = (string) Str::uuid();
    }

    protected function tearDown(): void
    {
        // subscriber_user_id is a cross-DB reference (no enforced FK) — clean up
        // explicitly; rows are hard-deleted via the owner connection (bypasses RLS).
        DB::connection('billing')->table('stripe_invoice_projections')
            ->whereIn('subscriber_user_id', [$this->subscriberId, $this->otherUserId])->delete();
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

    /** A complete projection row payload. */
    private function projectionRow(string $userId): array
    {
        return [
            'id'                 => (string) Str::uuid(),
            'subscriber_user_id' => $userId,
            'stripe_invoice_id'  => 'in_' . Str::random(24),
            'status'             => 'paid',
            'amount_cents'       => 999,
            'currency'           => 'USD',
        ];
    }

    /** Seed a projection row directly (owner connection, bypasses RLS). */
    private function seedProjection(string $userId): string
    {
        $row = $this->projectionRow($userId);
        DB::connection('billing')->table('stripe_invoice_projections')->insert($row);

        return $row['id'];
    }

    public function test_rls_is_enabled_on_projection(): void
    {
        $row = DB::connection('billing')->selectOne(
            "SELECT relrowsecurity FROM pg_class WHERE relname = 'stripe_invoice_projections'"
        );
        $this->assertTrue((bool) $row->relrowsecurity, 'RLS must be enabled on stripe_invoice_projections.');
    }

    public function test_subscriber_can_read_own_projection(): void
    {
        $this->seedProjection($this->subscriberId);
        $this->setContext($this->subscriberId, '');

        $this->assertSame(1, DB::connection(self::RUNTIME)->table('stripe_invoice_projections')
            ->where('subscriber_user_id', $this->subscriberId)->count());
    }

    public function test_subscriber_cannot_read_another_users_projection(): void
    {
        $this->seedProjection($this->subscriberId);
        $this->setContext($this->otherUserId, '');

        $this->assertSame(0, DB::connection(self::RUNTIME)->table('stripe_invoice_projections')
            ->where('subscriber_user_id', $this->subscriberId)->count());
    }

    public function test_staff_can_read_any_projection(): void
    {
        $this->seedProjection($this->subscriberId);
        $this->setContext($this->otherUserId, 'staff');

        $this->assertSame(1, DB::connection(self::RUNTIME)->table('stripe_invoice_projections')
            ->where('subscriber_user_id', $this->subscriberId)->count());
    }

    public function test_runtime_cannot_insert_projection(): void
    {
        $this->setContext($this->subscriberId, '');

        $this->expectException(QueryException::class);

        // No write policy exists — RLS default-denies the INSERT even for own row.
        DB::connection(self::RUNTIME)->table('stripe_invoice_projections')
            ->insert($this->projectionRow($this->subscriberId));
    }

    public function test_runtime_cannot_update_projection(): void
    {
        $id = $this->seedProjection($this->subscriberId);
        $this->setContext($this->subscriberId, '');

        // No UPDATE/ALL policy — the row is invisible to UPDATE, so nothing is
        // affected (system-authored: a user can never mutate a projected invoice).
        $affected = DB::connection(self::RUNTIME)->table('stripe_invoice_projections')
            ->where('id', $id)->update(['status' => 'void']);

        $this->assertSame(0, $affected, 'A subscriber must not be able to mutate a projected invoice.');
    }
}
