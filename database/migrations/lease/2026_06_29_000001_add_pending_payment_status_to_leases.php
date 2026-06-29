<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add a 'pending_payment' lease status between signing and active.
 *
 * A lease used to flip straight to 'active' the moment both parties signed,
 * regardless of whether the lease balance was paid — so an unpaid hunter could
 * check in (field access gates on status='active'). Signing and "paid & usable
 * in the field" are now two distinct states: a signed lease with an outstanding
 * balance sits in 'pending_payment' and only becomes 'active' once the balance
 * is settled. Check-in, gate QR and the stand map already gate on 'active', so
 * they exclude 'pending_payment' for free.
 */
return new class extends Migration
{
    protected $connection = 'lease';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE leases DROP CONSTRAINT IF EXISTS leases_status_check;
            ALTER TABLE leases ADD  CONSTRAINT leases_status_check
                CHECK (status IN ('pending_signatures', 'pending_payment', 'active', 'expired', 'terminated', 'cancelled'));
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE leases DROP CONSTRAINT IF EXISTS leases_status_check;
            ALTER TABLE leases ADD  CONSTRAINT leases_status_check
                CHECK (status IN ('pending_signatures', 'active', 'expired', 'terminated', 'cancelled'));
        SQL);
    }
};
