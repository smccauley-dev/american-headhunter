<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'property';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<SQL
            CREATE TABLE property_ownership_verifications (
                id                   UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                property_id          UUID         NOT NULL REFERENCES properties (id) ON DELETE CASCADE,
                submitted_by_user_id UUID         NOT NULL,  -- References DB 1 (Identity) users.id
                owner_type           VARCHAR(12)  NOT NULL
                                         CHECK (owner_type IN ('individual', 'company', 'manager')),
                entity_name          VARCHAR(200) NULL,
                status               VARCHAR(12)  NOT NULL DEFAULT 'pending'
                                         CHECK (status IN ('pending', 'approved', 'rejected')),
                document_ids         JSONB        NOT NULL DEFAULT '[]',  -- References DB 11 (Documents) documents.id
                certification_name   VARCHAR(200) NOT NULL,
                certified_at         TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                reviewed_by_user_id  UUID         NULL,  -- References DB 1 (Identity) users.id (staff reviewer)
                reviewed_at          TIMESTAMPTZ  NULL,
                review_notes         TEXT         NULL,
                created_at           TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at           TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                deleted_at           TIMESTAMPTZ  NULL
            );

            CREATE INDEX idx_ownership_verifications_property    ON property_ownership_verifications (property_id);
            CREATE INDEX idx_ownership_verifications_status      ON property_ownership_verifications (status);
            CREATE INDEX idx_ownership_verifications_deleted_at  ON property_ownership_verifications (deleted_at) WHERE deleted_at IS NOT NULL;

            -- At most one open (pending) submission per property at a time; a resubmit
            -- supersedes the prior pending row (soft-deleted by the service first).
            CREATE UNIQUE INDEX uq_ownership_verifications_one_pending
                ON property_ownership_verifications (property_id)
                WHERE status = 'pending' AND deleted_at IS NULL;

            CREATE TRIGGER trg_ownership_verifications_updated_at
                BEFORE UPDATE ON property_ownership_verifications
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();

            COMMENT ON TABLE property_ownership_verifications IS
                'Landowner proof of ownership or management for a property, reviewed by '
                'staff before the property may go active/live. owner_type distinguishes an '
                'individual owner, a company/entity owner, and a manager/agent acting on '
                'behalf of the owner. document_ids reference DB 11 proof documents (deed, '
                'county tax record, plat, management agreement, entity formation docs). The '
                'submitter accepts a penalty-of-perjury attestation (certification_name / '
                'certified_at). No RLS: like every property child table, access is scoped in '
                'the service layer via PropertyService::userCanManageProperty (admin via '
                'Filament as ah_system).';
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(
            'DROP TABLE IF EXISTS property_ownership_verifications;'
        );
    }
};
