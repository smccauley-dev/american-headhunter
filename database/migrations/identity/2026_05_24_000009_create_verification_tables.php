<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'identity';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE background_check_results (
                id                    UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                user_id               UUID         NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                provider              VARCHAR(50)  NOT NULL DEFAULT 'checkr',
                provider_report_id    VARCHAR(255) NOT NULL,
                status                VARCHAR(20)  NOT NULL
                                          CHECK (status IN ('pending', 'clear', 'consider', 'suspended', 'dispute')),
                report_type           VARCHAR(50)  NOT NULL,
                initiated_at          TIMESTAMPTZ  NOT NULL,
                completed_at          TIMESTAMPTZ  NULL,
                expires_at            TIMESTAMPTZ  NULL,
                raw_result_encrypted  TEXT         NULL,
                created_at            TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at            TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            );

            CREATE INDEX idx_background_check_results_user_id ON background_check_results (user_id);
            CREATE INDEX idx_background_check_results_status  ON background_check_results (status);
            CREATE INDEX idx_background_check_results_expires ON background_check_results (expires_at);

            CREATE TRIGGER set_updated_at
                BEFORE UPDATE ON background_check_results
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();

            CREATE TABLE ofac_screening_results (
                id                      UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                user_id                 UUID        NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                status                  VARCHAR(10) NOT NULL
                                            CHECK (status IN ('clear', 'match', 'pending')),
                screened_at             TIMESTAMPTZ NOT NULL,
                next_screening_at       TIMESTAMPTZ NULL,
                match_details_encrypted TEXT        NULL,
                created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW()
            );

            CREATE INDEX idx_ofac_screening_results_user_id
                ON ofac_screening_results (user_id);
            CREATE INDEX idx_ofac_screening_results_next_screening
                ON ofac_screening_results (next_screening_at)
                WHERE next_screening_at IS NOT NULL;
            CREATE INDEX idx_ofac_screening_results_status
                ON ofac_screening_results (status);

            CREATE TABLE identity_verifications (
                id                   UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                user_id              UUID         NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                provider             VARCHAR(50)  NOT NULL
                                         CHECK (provider IN ('id_me', 'stripe_identity', 'manual')),
                verification_type    VARCHAR(30)  NOT NULL
                                         CHECK (verification_type IN ('identity', 'veteran', 'age')),
                status               VARCHAR(20)  NOT NULL
                                         CHECK (status IN ('pending', 'verified', 'failed', 'expired')),
                provider_session_id  VARCHAR(255) NULL,
                verified_at          TIMESTAMPTZ  NULL,
                expires_at           TIMESTAMPTZ  NULL,
                created_at           TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at           TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            );

            CREATE INDEX idx_identity_verifications_user_id ON identity_verifications (user_id);
            CREATE INDEX idx_identity_verifications_status  ON identity_verifications (status);

            CREATE TRIGGER set_updated_at_identity_verifications
                BEFORE UPDATE ON identity_verifications
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();

            CREATE TABLE veteran_verifications (
                id                   UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                user_id              UUID         NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                method               VARCHAR(30)  NOT NULL
                                         CHECK (method IN ('id_me', 'dd214_upload')),
                status               VARCHAR(20)  NOT NULL
                                         CHECK (status IN ('pending', 'approved', 'rejected')),
                document_id          UUID         NULL,
                id_me_uuid           VARCHAR(255) NULL,
                verified_at          TIMESTAMPTZ  NULL,
                reviewed_by_user_id  UUID         NULL REFERENCES users (id) ON DELETE SET NULL,
                created_at           TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at           TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            );

            CREATE INDEX idx_veteran_verifications_user_id ON veteran_verifications (user_id);
            CREATE INDEX idx_veteran_verifications_status  ON veteran_verifications (status);

            CREATE TRIGGER set_updated_at_veteran_verifications
                BEFORE UPDATE ON veteran_verifications
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            DROP TABLE IF EXISTS veteran_verifications CASCADE;
            DROP TABLE IF EXISTS identity_verifications CASCADE;
            DROP TABLE IF EXISTS ofac_screening_results CASCADE;
            DROP TABLE IF EXISTS background_check_results CASCADE;
        SQL);
    }
};
