<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5.5 — route the booking deposit through Stripe Connect as a destination
 * charge, clearing the deferred-disbursement debt the table shipped with. The
 * customer still pays the same amount_cents, but transfer_data[destination] now
 * routes the net to the landowner's connected account at charge time and
 * application_fee_amount is the platform's cut — so a collected deposit is settled
 * to the landowner immediately (status 'disbursed') instead of sitting captured on
 * the platform (payout_id NULL forever).
 *
 * These columns mirror the lease_payments shape: stripe_account_id (the destination),
 * application_fee_cents (platform revenue), net_cents (auto-transferred), and
 * stripe_transfer_id (the transfer Stripe auto-creates). All nullable — historical
 * pre-Connect deposits keep NULLs and remain platform-held (flagged for manual
 * reconciliation; this migration only changes go-forward behavior).
 */
return new class extends Migration
{
    protected $connection = 'billing';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE booking_deposits
                ADD COLUMN stripe_account_id     VARCHAR(100) NULL,  -- the landowner's Connect account (transfer destination)
                ADD COLUMN application_fee_cents  BIGINT       NULL,  -- platform revenue (tier fee)
                ADD COLUMN net_cents              BIGINT       NULL,  -- auto-transferred to the landowner
                ADD COLUMN stripe_transfer_id     VARCHAR(100) NULL;  -- the destination transfer Stripe auto-creates
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE booking_deposits
                DROP COLUMN IF EXISTS stripe_account_id,
                DROP COLUMN IF EXISTS application_fee_cents,
                DROP COLUMN IF EXISTS net_cents,
                DROP COLUMN IF EXISTS stripe_transfer_id;
        SQL);
    }
};
