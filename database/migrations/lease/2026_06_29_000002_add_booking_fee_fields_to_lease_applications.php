<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Vet-first booking fee (Slice 2). An application is approved BEFORE any money
 * changes hands; approval starts a 24-hour window in which the applicant must pay
 * the (held, non-refundable) booking fee to claim the spot. The first approved
 * applicant to pay wins — the rest are 'closed'.
 *
 *  - booking_fee_deadline: now() + 24h, set on approval. The deadline-enforcement
 *    command closes any still-approved application past it ("Booking Fee was not paid").
 *  - closed_reason: human-readable note for a 'closed' application (deadline lapsed,
 *    or another applicant booked the listing first).
 *  - status 'closed': a terminal state distinct from rejected/withdrawn/expired —
 *    the application was viable but lost the booking-fee race or let the clock run out.
 */
return new class extends Migration
{
    protected $connection = 'lease';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE lease_applications
                ADD COLUMN booking_fee_deadline TIMESTAMPTZ  NULL,
                ADD COLUMN closed_reason         VARCHAR(160) NULL;

            ALTER TABLE lease_applications DROP CONSTRAINT IF EXISTS lease_applications_status_check;
            ALTER TABLE lease_applications ADD  CONSTRAINT lease_applications_status_check
                CHECK (status IN ('pending', 'under_review', 'approved', 'rejected', 'withdrawn', 'expired', 'closed'));

            CREATE INDEX idx_lease_applications_booking_fee_deadline
                ON lease_applications (booking_fee_deadline)
                WHERE booking_fee_deadline IS NOT NULL;
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            DROP INDEX IF EXISTS idx_lease_applications_booking_fee_deadline;

            ALTER TABLE lease_applications DROP CONSTRAINT IF EXISTS lease_applications_status_check;
            ALTER TABLE lease_applications ADD  CONSTRAINT lease_applications_status_check
                CHECK (status IN ('pending', 'under_review', 'approved', 'rejected', 'withdrawn', 'expired'));

            ALTER TABLE lease_applications
                DROP COLUMN IF EXISTS booking_fee_deadline,
                DROP COLUMN IF EXISTS closed_reason;
        SQL);
    }
};
