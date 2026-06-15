<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SEC-043 stage 2 — re-target the billing RLS policies from the table owner
 * (ah_app, which bypasses RLS) to the non-owner runtime role (ah_runtime) so
 * they actually enforce, and harden the user-id casts with NULLIF(..., '') so
 * an unauthenticated/empty context resolves to NULL instead of throwing on
 * ''::UUID. Semantics otherwise unchanged. Safe while the app remains on
 * ah_app — the owner keeps full access until the connection is flipped.
 */
return new class extends Migration
{
    protected $connection = 'billing';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            DROP POLICY IF EXISTS invoices_parties_and_staff ON invoices;
            DROP POLICY IF EXISTS payment_methods_own_user   ON payment_methods;
            DROP POLICY IF EXISTS payments_own_user          ON payments;
            DROP POLICY IF EXISTS payouts_own_user           ON payouts;
            DROP POLICY IF EXISTS w9_records_own_user         ON w9_records;

            CREATE POLICY invoices_parties_and_staff ON invoices
                FOR SELECT TO ah_runtime
                USING (
                    payer_user_id = NULLIF(current_setting('app.current_user_id', true), '')::UUID
                    OR payee_user_id = NULLIF(current_setting('app.current_user_id', true), '')::UUID
                    OR current_setting('app.user_role', true) IN ('staff', 'super_admin')
                );

            CREATE POLICY payment_methods_own_user ON payment_methods
                FOR ALL TO ah_runtime
                USING (
                    user_id = NULLIF(current_setting('app.current_user_id', true), '')::UUID
                    OR current_setting('app.user_role', true) IN ('staff', 'super_admin')
                );

            CREATE POLICY payments_own_user ON payments
                FOR SELECT TO ah_runtime
                USING (
                    payer_user_id = NULLIF(current_setting('app.current_user_id', true), '')::UUID
                    OR current_setting('app.user_role', true) IN ('staff', 'super_admin')
                );

            CREATE POLICY payouts_own_user ON payouts
                FOR SELECT TO ah_runtime
                USING (
                    payee_user_id = NULLIF(current_setting('app.current_user_id', true), '')::UUID
                    OR current_setting('app.user_role', true) IN ('staff', 'super_admin')
                );

            CREATE POLICY w9_records_own_user ON w9_records
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
            DROP POLICY IF EXISTS invoices_parties_and_staff ON invoices;
            DROP POLICY IF EXISTS payment_methods_own_user   ON payment_methods;
            DROP POLICY IF EXISTS payments_own_user          ON payments;
            DROP POLICY IF EXISTS payouts_own_user           ON payouts;
            DROP POLICY IF EXISTS w9_records_own_user         ON w9_records;

            CREATE POLICY invoices_parties_and_staff ON invoices
                FOR SELECT TO ah_app
                USING (
                    payer_user_id = current_setting('app.current_user_id', true)::UUID
                    OR payee_user_id = current_setting('app.current_user_id', true)::UUID
                    OR current_setting('app.user_role', true) IN ('staff', 'super_admin')
                );

            CREATE POLICY payment_methods_own_user ON payment_methods
                FOR ALL TO ah_app
                USING (
                    user_id = current_setting('app.current_user_id', true)::UUID
                    OR current_setting('app.user_role', true) IN ('staff', 'super_admin')
                );

            CREATE POLICY payments_own_user ON payments
                FOR SELECT TO ah_app
                USING (
                    payer_user_id = current_setting('app.current_user_id', true)::UUID
                    OR current_setting('app.user_role', true) IN ('staff', 'super_admin')
                );

            CREATE POLICY payouts_own_user ON payouts
                FOR SELECT TO ah_app
                USING (
                    payee_user_id = current_setting('app.current_user_id', true)::UUID
                    OR current_setting('app.user_role', true) IN ('staff', 'super_admin')
                );

            CREATE POLICY w9_records_own_user ON w9_records
                FOR SELECT TO ah_app
                USING (
                    user_id = current_setting('app.current_user_id', true)::UUID
                    OR current_setting('app.user_role', true) IN ('staff', 'super_admin')
                );
        SQL);
    }
};
