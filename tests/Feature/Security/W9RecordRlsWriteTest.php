<?php

namespace Tests\Feature\Security;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SEC-045 regression — payee W-9 writes are permitted (and correctly scoped)
 * under the non-owner ah_runtime role.
 *
 * The defect: after the SEC-043 role flip, w9_records had only a FOR SELECT
 * policy. With RLS enabled and no permissive write policy, PostgreSQL
 * default-denies every INSERT/UPDATE for a non-owner role — so a payee
 * submitting or certifying their own W-9 from the member portal (ah_runtime)
 * would silently fail. (invoices / payments / payouts are deliberately left
 * SELECT-only for ah_runtime: they are system-authored and must never be
 * runtime-writable, so they are not covered here.)
 *
 * The fix adds self-service write policies (w9_records_insert_self /
 * w9_records_update_self). This test connects EXPLICITLY as ah_runtime and
 * proves:
 *
 *   1. a payee may INSERT their own W-9
 *   2. a payee may UPDATE (certify) their own W-9
 *   3. a payee may NOT INSERT a row attributed to another user (WITH CHECK)
 *   4. a payee may NOT UPDATE another user's W-9 (USING filters it out)
 *   5. staff may INSERT on a user's behalf (support override)
 *
 * Postgres-only. Skips cleanly when an ah_runtime connection is unavailable.
 */
class W9RecordRlsWriteTest extends TestCase
{
    private const RUNTIME = 'billing_rls_write_test';

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

        $this->payeeId     = (string) Str::uuid();
        $this->otherUserId = (string) Str::uuid();
    }

    protected function tearDown(): void
    {
        // w9_records has no deleted_at and no parent cascade (user_id is a
        // cross-DB reference, not an enforced FK) — clean up explicitly.
        DB::connection('billing')->table('w9_records')
            ->whereIn('user_id', [$this->payeeId, $this->otherUserId])->delete();
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

    /** A complete w9_records row payload (DB does not validate tin ciphertext). */
    private function w9Row(string $userId): array
    {
        return [
            'id'                 => (string) Str::uuid(),
            'user_id'            => $userId,
            'legal_name'         => 'Test Payee',
            'tax_classification' => 'individual',
            'tin_type'           => 'ssn',
            'tin'                => 'enc:test',
            'tin_last_four'      => '6789',
            'address_line1'      => '1 Ranch Rd',
            'city'               => 'Austin',
            'state_code'         => 'TX',
            'postal_code'        => '78701',
        ];
    }

    /** Seed a W-9 directly (owner connection, bypasses RLS). */
    private function seedW9(string $userId): string
    {
        $row = $this->w9Row($userId);
        DB::connection('billing')->table('w9_records')->insert($row);

        return $row['id'];
    }

    public function test_rls_is_enabled_on_w9_records(): void
    {
        $row = DB::connection('billing')->selectOne(
            "SELECT relrowsecurity FROM pg_class WHERE relname = 'w9_records'"
        );
        $this->assertTrue((bool) $row->relrowsecurity, 'RLS must be enabled on w9_records.');
    }

    public function test_payee_can_insert_own_w9(): void
    {
        $this->setContext($this->payeeId, '');

        DB::connection(self::RUNTIME)->table('w9_records')->insert($this->w9Row($this->payeeId));

        $this->assertSame(1, DB::connection('billing')->table('w9_records')
            ->where('user_id', $this->payeeId)->count());
    }

    public function test_payee_can_certify_own_w9(): void
    {
        $id = $this->seedW9($this->payeeId);
        $this->setContext($this->payeeId, '');

        $affected = DB::connection(self::RUNTIME)->table('w9_records')
            ->where('id', $id)->update(['certified_at' => now()]);

        $this->assertSame(1, $affected, 'A payee must be able to certify their own W-9.');
    }

    public function test_payee_cannot_insert_for_another_user(): void
    {
        $this->setContext($this->otherUserId, '');

        $this->expectException(QueryException::class);

        // user_id is not the current user — WITH CHECK must reject.
        DB::connection(self::RUNTIME)->table('w9_records')->insert($this->w9Row($this->payeeId));
    }

    public function test_payee_cannot_update_another_users_w9(): void
    {
        $id = $this->seedW9($this->payeeId);
        $this->setContext($this->otherUserId, '');

        // USING hides the row from this user, so the UPDATE matches nothing.
        $affected = DB::connection(self::RUNTIME)->table('w9_records')
            ->where('id', $id)->update(['certified_at' => now()]);

        $this->assertSame(0, $affected, "A payee must not be able to modify another user's W-9.");
    }

    public function test_staff_can_insert_on_behalf_of_user(): void
    {
        $this->setContext($this->otherUserId, 'staff');

        DB::connection(self::RUNTIME)->table('w9_records')->insert($this->w9Row($this->payeeId));

        $this->assertSame(1, DB::connection('billing')->table('w9_records')
            ->where('user_id', $this->payeeId)->count());
    }
}
