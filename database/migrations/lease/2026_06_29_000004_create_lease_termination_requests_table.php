<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A hunter-initiated request to end an active lease early. The landowner approves
 * or denies it; on approval the lease is terminated and the hunter forfeits the
 * security deposit as a non-contestable early-exit penalty (the landowner keeps
 * it). Distinct from the landowner's violation termination, which forfeits the
 * deposit as a contestable claim and can also touch prepaid rent.
 *
 * Same-DB FK to leases (DB 3); user ids are bare cross-DB references (DB 1).
 * Secure by default: RLS on, lease parties + staff may read, writes only via the
 * trusted ah_system role (the member-portal routes run under db.system).
 */
return new class extends Migration
{
    protected $connection = 'lease';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE lease_termination_requests (
                id                   UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                lease_id             UUID        NOT NULL REFERENCES leases (id) ON DELETE CASCADE,
                requested_by_user_id UUID        NOT NULL,  -- References DB 1 (Identity) users.id (the lessee/hunter)
                reason               TEXT        NOT NULL,
                status               VARCHAR(10) NOT NULL DEFAULT 'pending'
                                         CHECK (status IN ('pending', 'approved', 'denied')),
                decided_by_user_id   UUID        NULL,       -- References DB 1 (Identity) users.id (the lessor/landowner)
                decision_note        TEXT        NULL,
                decided_at           TIMESTAMPTZ NULL,
                created_at           TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at           TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                deleted_at           TIMESTAMPTZ NULL
            );

            CREATE INDEX idx_lease_termination_requests_lease_id ON lease_termination_requests (lease_id);

            -- At most one open (pending) request per lease.
            CREATE UNIQUE INDEX uq_lease_termination_requests_open
                ON lease_termination_requests (lease_id)
                WHERE status = 'pending' AND deleted_at IS NULL;

            CREATE TRIGGER trg_lease_termination_requests_updated_at
                BEFORE UPDATE ON lease_termination_requests
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();

            -- RLS: lease parties + staff read; no write policy (system-authored).
            ALTER TABLE lease_termination_requests ENABLE ROW LEVEL SECURITY;

            CREATE POLICY lease_termination_requests_parties_and_staff ON lease_termination_requests
                FOR SELECT TO ah_runtime
                USING (
                    lease_id IN (
                        SELECT id FROM leases
                        WHERE lessee_user_id = NULLIF(current_setting('app.current_user_id', true), '')::UUID
                           OR lessor_user_id = NULLIF(current_setting('app.current_user_id', true), '')::UUID
                    )
                    OR current_setting('app.user_role', true) IN ('staff', 'super_admin')
                );
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS lease_termination_requests CASCADE;');
    }
};
