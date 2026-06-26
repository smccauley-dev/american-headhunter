<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5.x — damage_claims (DB 10), the landowner's itemized damage-claim intake.
 *
 * A landowner files a claim for property/equipment damage with photo evidence; an
 * admin reviews and approves an amount, denies, marks paid, or marks covered by
 * insurance. An approved claim can optionally drive a deposit forfeiture-claim via
 * SecurityDepositService::forfeit().
 *
 * System-authored, runtime-read-only (SEC-045), same shape as lease_disputes: RLS
 * on, a single FOR SELECT policy TO ah_runtime (the claimant + staff), no write
 * policy. Landowners file claims via the db.system route (ah_system, BYPASSRLS);
 * the Filament admin panel reviews (also ah_system). Only the claimant (and staff)
 * can read a claim — the lessee's visibility into the loss comes through the deposit
 * forfeiture and any dispute they raise, so no respondent column is needed here.
 *
 * Adds 'covered' to the documented status set and insurance columns (mirroring the
 * deposit) so a covered loss routes to the insurer rather than the deposit.
 */
return new class extends Migration
{
    protected $connection = 'incidents';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE damage_claims (
                id                      UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                lease_id                UUID NOT NULL,           -- References DB 3 (Lease) leases.id
                security_deposit_id     UUID,                    -- References DB 4 (Billing) security_deposits.id — set when settled from the deposit
                claimant_user_id        UUID NOT NULL,           -- References DB 1 (Identity) users.id — the landowner
                claim_type              VARCHAR(30) NOT NULL,
                status                  VARCHAR(20) NOT NULL DEFAULT 'submitted',
                description             TEXT NOT NULL,
                amount_claimed_cents    BIGINT NOT NULL,
                amount_approved_cents   BIGINT,                  -- NULL until reviewed
                evidence_document_ids   JSONB NOT NULL DEFAULT '[]',    -- Array of DB 11 documents.id UUIDs
                insurance_covered_party VARCHAR(12)  NULL
                    CHECK (insurance_covered_party IN ('landowner','hunter','none')),
                insurer_name            VARCHAR(120) NULL,
                policy_number           VARCHAR(80)  NULL,
                coi_document_id         UUID         NULL,       -- References DB 11 (Documents) documents.id (insurance_certificate)
                coverage_status         VARCHAR(12)  NULL
                    CHECK (coverage_status IN ('none','claimed','covered','denied')),
                reviewed_by_user_id     UUID,                    -- References DB 1 (Identity) users.id — admin reviewer
                review_notes            TEXT,
                resolved_at             TIMESTAMPTZ,
                created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                deleted_at              TIMESTAMPTZ,

                CONSTRAINT chk_damage_claims_type
                    CHECK (claim_type IN ('property_damage', 'equipment_damage', 'other')),
                CONSTRAINT chk_damage_claims_status
                    CHECK (status IN ('submitted', 'under_review', 'approved', 'denied', 'paid', 'covered')),
                CONSTRAINT chk_damage_claims_amount
                    CHECK (amount_claimed_cents > 0)
            );

            CREATE INDEX idx_damage_claims_lease_id ON damage_claims (lease_id)
                WHERE deleted_at IS NULL;
            CREATE INDEX idx_damage_claims_claimant ON damage_claims (claimant_user_id)
                WHERE deleted_at IS NULL;
            CREATE INDEX idx_damage_claims_status ON damage_claims (status)
                WHERE deleted_at IS NULL AND status NOT IN ('paid', 'denied', 'covered');
            CREATE INDEX idx_damage_claims_reviewer ON damage_claims (reviewed_by_user_id)
                WHERE reviewed_by_user_id IS NOT NULL;

            CREATE TRIGGER set_updated_at BEFORE UPDATE ON damage_claims
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();

            ALTER TABLE damage_claims ENABLE ROW LEVEL SECURITY;

            -- Read-only for the runtime role: the claimant sees their own claim;
            -- staff/super_admin see all. No INSERT/UPDATE/DELETE policy by design —
            -- writes are system-authored (ah_system, BYPASSRLS).
            CREATE POLICY damage_claims_claimant_and_staff ON damage_claims
                FOR SELECT TO ah_runtime
                USING (
                    claimant_user_id = NULLIF(current_setting('app.current_user_id', true), '')::UUID
                    OR current_setting('app.user_role', true) IN ('staff', 'super_admin')
                );
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS damage_claims CASCADE;');
    }
};
