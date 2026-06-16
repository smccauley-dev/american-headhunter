<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SEC-045 (SEC-043 lineage) — restore payee W-9 self-service writes under the
 * non-owner ah_runtime role.
 *
 * After the SEC-043 role flip, the billing RLS tables are SELECT-only for
 * ah_runtime, so under PostgreSQL default-deny every INSERT/UPDATE on them
 * fails for a non-owner role. For invoices, payments, and payouts that is
 * correct and intentional: they are system-authored financial-integrity
 * records (Stripe webhooks, queue jobs, Filament admin — all ah_system, which
 * BYPASSRLS) and must NEVER be writable on a user-facing runtime connection,
 * or a logged-in user could forge them. Their read-only state is the fail-safe
 * control; any Phase 5 service that authors them runs under db.system.
 *
 * w9_records is the one billing table a user legitimately authors: a payee
 * (landowner / outfitter / seller) submits and certifies their OWN W-9 from
 * the member portal on an ah_runtime request. It therefore needs an additive
 * write policy. The predicate mirrors the existing SELECT policy
 * (w9_records_own_user): own user, or staff/super_admin. Purely additive —
 * reads, admin, and system paths are untouched. payment_methods already has a
 * FOR ALL (WITH CHECK) policy and needs nothing here.
 */
return new class extends Migration
{
    protected $connection = 'billing';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE POLICY w9_records_insert_self ON w9_records
                FOR INSERT TO ah_runtime
                WITH CHECK (
                    user_id = NULLIF(current_setting('app.current_user_id', true), '')::UUID
                    OR current_setting('app.user_role', true) IN ('staff', 'super_admin')
                );

            CREATE POLICY w9_records_update_self ON w9_records
                FOR UPDATE TO ah_runtime
                USING (
                    user_id = NULLIF(current_setting('app.current_user_id', true), '')::UUID
                    OR current_setting('app.user_role', true) IN ('staff', 'super_admin')
                )
                WITH CHECK (
                    user_id = NULLIF(current_setting('app.current_user_id', true), '')::UUID
                    OR current_setting('app.user_role', true) IN ('staff', 'super_admin')
                );
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            DROP POLICY IF EXISTS w9_records_insert_self ON w9_records;
            DROP POLICY IF EXISTS w9_records_update_self ON w9_records;
        SQL);
    }
};
