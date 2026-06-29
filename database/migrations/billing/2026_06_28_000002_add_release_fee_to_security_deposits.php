<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5.x — track the landowner-borne Stripe processing fee recovered on a clean
 * security-deposit release. The deposit is captured on the PLATFORM account (a
 * refundable hold, never routed to the landowner), so on release the platform
 * refunds the hunter in full and Stripe keeps its non-refundable fee. That fee is
 * the landowner's cost (fee_schedules category=security_deposit, payer=landowner);
 * release() recovers it best-effort by debiting the landowner's Connect balance.
 *
 * release_fee_status records the outcome: 'collected' (the debit succeeded — id in
 * release_fee_transfer_id) or 'deferred' (the landowner has no chargeable Connect
 * balance yet, so the fee is recorded as owed and not collected). NULL means no
 * landowner-borne fee was configured for the deposit. System-authored columns,
 * written only by the ah_system release path — the table's read-only RLS is
 * unchanged.
 */
return new class extends Migration
{
    protected $connection = 'billing';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE security_deposits
                ADD COLUMN release_fee_cents       BIGINT       NULL,
                ADD COLUMN release_fee_status      VARCHAR(12)  NULL
                    CHECK (release_fee_status IN ('collected', 'deferred')),
                ADD COLUMN release_fee_transfer_id VARCHAR(100) NULL;
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE security_deposits
                DROP COLUMN IF EXISTS release_fee_cents,
                DROP COLUMN IF EXISTS release_fee_status,
                DROP COLUMN IF EXISTS release_fee_transfer_id;
        SQL);
    }
};
