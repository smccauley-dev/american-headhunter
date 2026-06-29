<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Vet-first booking fee (Slice 2). The booking deposit becomes a held, application-
 * scoped booking FEE rather than a lease-scoped destination charge:
 *
 *  - It is paid AFTER approval but BEFORE a lease exists (application_id), so lease_id
 *    is now nullable — it is backfilled when the paying applicant wins the spot and a
 *    lease is created.
 *  - The fee is HELD on the platform (a plain charge, not a destination charge) and
 *    routed on outcome: 'held' while pending, 'disbursed' to the landowner when the
 *    lease completes, 'forfeited' to the landowner when the 7-day window lapses, or
 *    'refunded' to the applicant when they lose the first-to-pay race.
 *  - stripe_charge_id is captured so the held charge can be transferred or refunded.
 *
 * Pre-existing 'collected'/'disbursed'/'pending' rows (the old destination-charge
 * model) remain valid — the CHECK is widened, not replaced.
 */
return new class extends Migration
{
    protected $connection = 'billing';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE booking_deposits
                ADD COLUMN application_id  UUID         NULL,  -- References DB 3 (Lease) lease_applications.id
                ADD COLUMN stripe_charge_id VARCHAR(100) NULL, -- the held charge (source for transfer/refund)
                ADD COLUMN forfeited_at    TIMESTAMPTZ  NULL,
                ADD COLUMN refunded_at     TIMESTAMPTZ  NULL;

            ALTER TABLE booking_deposits ALTER COLUMN lease_id DROP NOT NULL;

            ALTER TABLE booking_deposits DROP CONSTRAINT IF EXISTS booking_deposits_status_check;
            ALTER TABLE booking_deposits ADD  CONSTRAINT booking_deposits_status_check
                CHECK (status IN ('pending', 'collected', 'disbursed', 'held', 'forfeited', 'refunded'));

            CREATE INDEX idx_booking_deposits_application_id
                ON booking_deposits (application_id)
                WHERE application_id IS NOT NULL;
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            DROP INDEX IF EXISTS idx_booking_deposits_application_id;

            ALTER TABLE booking_deposits DROP CONSTRAINT IF EXISTS booking_deposits_status_check;
            ALTER TABLE booking_deposits ADD  CONSTRAINT booking_deposits_status_check
                CHECK (status IN ('pending', 'collected', 'disbursed'));

            ALTER TABLE booking_deposits ALTER COLUMN lease_id SET NOT NULL;

            ALTER TABLE booking_deposits
                DROP COLUMN IF EXISTS application_id,
                DROP COLUMN IF EXISTS stripe_charge_id,
                DROP COLUMN IF EXISTS forfeited_at,
                DROP COLUMN IF EXISTS refunded_at;
        SQL);
    }
};
