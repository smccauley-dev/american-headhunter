<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SEC-045 forgery-defense — bring stripe_accounts under the system-authored,
 * runtime-read-only pattern the rest of the billing money tables already use.
 *
 * The table was created (2026_06_14_000007) WITHOUT row-level security, but
 * ah_runtime carries a blanket DML grant (ALTER DEFAULT PRIVILEGES), so any
 * authenticated user could read or forge another landowner's Connect account row
 * — including the charges_enabled / payouts_enabled flags that gate receiving
 * money. Those flags are authored only by Stripe (the account.updated webhook)
 * and the onboarding flow, both of which run under ah_system (BYPASSRLS).
 *
 * This enables RLS with a single FOR SELECT policy TO ah_runtime (own row + staff)
 * and NO write policy, so the grant is inert for writes: the runtime path can read
 * its own account state but can never create or mutate one. Mirrors payouts /
 * security_deposits / the invoice projection.
 */
return new class extends Migration
{
    protected $connection = 'billing';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE stripe_accounts ENABLE ROW LEVEL SECURITY;

            CREATE POLICY stripe_accounts_own_user ON stripe_accounts
                FOR SELECT TO ah_runtime
                USING (
                    user_id = NULLIF(current_setting('app.current_user_id', true), '')::UUID
                    OR current_setting('app.user_role', true) IN ('staff', 'super_admin')
                );
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            DROP POLICY IF EXISTS stripe_accounts_own_user ON stripe_accounts;
            ALTER TABLE stripe_accounts DISABLE ROW LEVEL SECURITY;
        SQL);
    }
};
