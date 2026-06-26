<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5.x — lease_disputes (DB 10), the hunter's forfeiture-contest loop.
 *
 * When a landowner forfeits a hunter's security deposit, that forfeiture is only a
 * CLAIM (money is held, Trust Score is provisional). A hunter contests it by filing
 * a dispute here with photo evidence; an admin adjudicates and the outcome flows
 * back to SecurityDepositService (uphold/overturn/opt-out), which is the only place
 * money moves and Trust Score changes.
 *
 * First table in DB 10, so this migration defensively installs the pgcrypto/uuid
 * extensions and the shared updated_at trigger function.
 *
 * System-authored, runtime-read-only (SEC-045): RLS is enabled with a single FOR
 * SELECT policy TO ah_runtime (the two parties + staff) and NO write policy, so the
 * table-level DML grant ah_runtime inherits via ALTER DEFAULT PRIVILEGES is inert
 * for writes — every INSERT/UPDATE default-denies. Members file disputes only via
 * the db.system route (ah_system, BYPASSRLS); the Filament admin panel adjudicates
 * (also ah_system). A party can read their own dispute but can never forge one.
 *
 * Adds two columns beyond the documented schema: security_deposit_id (the forfeiture
 * being contested) and evidence_document_ids (DB 11 photo proof) — both bare UUID
 * refs assembled in the service layer, never SQL foreign keys.
 */
return new class extends Migration
{
    protected $connection = 'incidents';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE EXTENSION IF NOT EXISTS "pgcrypto";
            CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

            CREATE OR REPLACE FUNCTION trigger_set_updated_at()
            RETURNS TRIGGER AS $$
            BEGIN
                NEW.updated_at = NOW();
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TABLE lease_disputes (
                id                      UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                lease_id                UUID NOT NULL,           -- References DB 3 (Lease) leases.id
                security_deposit_id     UUID,                    -- References DB 4 (Billing) security_deposits.id — the contested forfeiture
                initiator_user_id       UUID NOT NULL,           -- References DB 1 (Identity) users.id (the contesting hunter)
                respondent_user_id      UUID NOT NULL,           -- References DB 1 (Identity) users.id (the landowner)
                dispute_type            VARCHAR(20) NOT NULL,
                status                  VARCHAR(20) NOT NULL DEFAULT 'open',
                description             TEXT NOT NULL,
                amount_disputed_cents   BIGINT,                  -- NULL if not a financial dispute
                evidence_document_ids   JSONB NOT NULL DEFAULT '[]',    -- Array of DB 11 documents.id UUIDs (photo proof)
                resolution              TEXT,
                resolved_at             TIMESTAMPTZ,
                created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                deleted_at              TIMESTAMPTZ,

                CONSTRAINT chk_lease_disputes_type
                    CHECK (dispute_type IN ('payment', 'access', 'damage', 'breach', 'other')),
                CONSTRAINT chk_lease_disputes_status
                    CHECK (status IN ('open', 'mediation', 'arbitration', 'resolved', 'escalated')),
                CONSTRAINT chk_lease_disputes_parties
                    CHECK (initiator_user_id <> respondent_user_id)
            );

            CREATE INDEX idx_lease_disputes_lease_id ON lease_disputes (lease_id)
                WHERE deleted_at IS NULL;
            CREATE INDEX idx_lease_disputes_deposit ON lease_disputes (security_deposit_id)
                WHERE security_deposit_id IS NOT NULL AND deleted_at IS NULL;
            CREATE INDEX idx_lease_disputes_initiator ON lease_disputes (initiator_user_id)
                WHERE deleted_at IS NULL;
            CREATE INDEX idx_lease_disputes_respondent ON lease_disputes (respondent_user_id)
                WHERE deleted_at IS NULL;
            CREATE INDEX idx_lease_disputes_status ON lease_disputes (status)
                WHERE deleted_at IS NULL AND status NOT IN ('resolved');

            CREATE TRIGGER set_updated_at BEFORE UPDATE ON lease_disputes
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();

            ALTER TABLE lease_disputes ENABLE ROW LEVEL SECURITY;

            -- Read-only for the runtime role: either party sees their own dispute;
            -- staff/super_admin see all. No INSERT/UPDATE/DELETE policy by design —
            -- writes are system-authored (ah_system, BYPASSRLS).
            CREATE POLICY lease_disputes_parties_and_staff ON lease_disputes
                FOR SELECT TO ah_runtime
                USING (
                    initiator_user_id = NULLIF(current_setting('app.current_user_id', true), '')::UUID
                    OR respondent_user_id = NULLIF(current_setting('app.current_user_id', true), '')::UUID
                    OR current_setting('app.user_role', true) IN ('staff', 'super_admin')
                );
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS lease_disputes CASCADE;');
    }
};
