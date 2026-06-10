<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'documents';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE esignature_requests (
                id                            UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                lease_id                      UUID         NOT NULL,  -- References DB 3 (Lease) leases.id
                requester_user_id             UUID         NOT NULL,  -- References DB 1 (Identity) users.id
                provider                      VARCHAR(50)  NOT NULL DEFAULT 'dropbox_sign',
                provider_signature_request_id VARCHAR(255),
                status                        VARCHAR(30)  NOT NULL DEFAULT 'pending',
                subject                       VARCHAR(255),
                message                       TEXT,
                signed_document_id            UUID         REFERENCES documents (id),
                requested_at                  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                completed_at                  TIMESTAMPTZ,
                expires_at                    TIMESTAMPTZ,
                created_at                    TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at                    TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

                CONSTRAINT uq_esignature_requests_provider_id
                    UNIQUE (provider_signature_request_id),
                CONSTRAINT chk_esignature_requests_status
                    CHECK (status IN ('pending', 'out_for_signature', 'completed', 'declined', 'expired', 'error'))
            );

            CREATE INDEX idx_esignature_requests_lease_id    ON esignature_requests (lease_id);
            CREATE INDEX idx_esignature_requests_requester   ON esignature_requests (requester_user_id);
            CREATE INDEX idx_esignature_requests_status      ON esignature_requests (status)
                WHERE status NOT IN ('completed', 'declined', 'expired');
            CREATE INDEX idx_esignature_requests_provider_id ON esignature_requests (provider_signature_request_id)
                WHERE provider_signature_request_id IS NOT NULL;

            CREATE TRIGGER set_updated_at BEFORE UPDATE ON esignature_requests
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();

            CREATE TABLE esignature_signers (
                id          UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                request_id  UUID         NOT NULL REFERENCES esignature_requests (id),
                user_id     UUID         NOT NULL,  -- References DB 1 (Identity) users.id
                email       VARCHAR(255) NOT NULL,
                name        VARCHAR(200) NOT NULL,
                order_num   SMALLINT     NOT NULL DEFAULT 0,
                status      VARCHAR(20)  NOT NULL DEFAULT 'pending',
                signed_at   TIMESTAMPTZ,
                declined_at TIMESTAMPTZ,
                created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                -- No updated_at — status changes are tracked via DB 9 audit log
                -- No deleted_at — signer records are permanent once created

                CONSTRAINT chk_esignature_signers_status
                    CHECK (status IN ('pending', 'viewed', 'signed', 'declined'))
            );

            CREATE INDEX idx_esignature_signers_request_id ON esignature_signers (request_id);
            CREATE INDEX idx_esignature_signers_user_id    ON esignature_signers (user_id);
            CREATE INDEX idx_esignature_signers_status     ON esignature_signers (request_id, status);
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            DROP TABLE IF EXISTS esignature_signers  CASCADE;
            DROP TABLE IF EXISTS esignature_requests CASCADE;
        SQL);
    }
};
