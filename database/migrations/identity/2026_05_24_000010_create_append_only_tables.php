<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'identity';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE trust_score_events (
                id          UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                user_id     UUID        NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                event_type  VARCHAR(60) NOT NULL
                                CHECK (event_type IN (
                                    'background_check_passed',
                                    'background_check_failed',
                                    'lease_completed',
                                    'lease_terminated_early',
                                    'dispute_raised',
                                    'dispute_resolved_for_user',
                                    'dispute_resolved_against_user',
                                    'verified_landowner',
                                    'email_verified',
                                    'phone_verified',
                                    'id_verified',
                                    'ofac_cleared',
                                    'ofac_match',
                                    'positive_review',
                                    'negative_review',
                                    'account_suspended',
                                    'admin_adjustment'
                                )),
                delta       SMALLINT    NOT NULL,
                score_after SMALLINT    NOT NULL,
                metadata    JSONB       NOT NULL DEFAULT '{}',
                created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
            );

            CREATE INDEX idx_trust_score_events_user_id    ON trust_score_events (user_id);
            CREATE INDEX idx_trust_score_events_event_type ON trust_score_events (event_type);
            CREATE INDEX idx_trust_score_events_created_at ON trust_score_events (created_at);

            CREATE TABLE login_history (
                id             UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                user_id        UUID        NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                ip_address     INET        NOT NULL,
                user_agent     TEXT        NULL,
                success        BOOLEAN     NOT NULL,
                failure_reason VARCHAR(50) NULL
                                   CHECK (failure_reason IN (
                                       'wrong_password',
                                       'account_locked',
                                       'account_suspended',
                                       'account_banned',
                                       'mfa_failed',
                                       'mfa_expired',
                                       'not_found'
                                   ) OR failure_reason IS NULL),
                mfa_used       BOOLEAN     NOT NULL DEFAULT false,
                created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW()
            );

            CREATE INDEX idx_login_history_user_id    ON login_history (user_id);
            CREATE INDEX idx_login_history_ip_address ON login_history (ip_address);
            CREATE INDEX idx_login_history_created_at ON login_history (created_at);
            CREATE INDEX idx_login_history_success    ON login_history (success);

            CREATE TABLE consent_log (
                id           UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                user_id      UUID        NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                consent_type VARCHAR(50) NOT NULL
                                 CHECK (consent_type IN (
                                     'terms_of_service',
                                     'privacy_policy',
                                     'ccpa',
                                     'marketing_emails',
                                     'sms_notifications'
                                 )),
                granted      BOOLEAN     NOT NULL,
                version      VARCHAR(20) NOT NULL,
                ip_address   INET        NULL,
                user_agent   TEXT        NULL,
                created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW()
            );

            CREATE INDEX idx_consent_log_user_id      ON consent_log (user_id);
            CREATE INDEX idx_consent_log_consent_type ON consent_log (consent_type);
            CREATE INDEX idx_consent_log_created_at   ON consent_log (created_at);
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            DROP TABLE IF EXISTS consent_log CASCADE;
            DROP TABLE IF EXISTS login_history CASCADE;
            DROP TABLE IF EXISTS trust_score_events CASCADE;
        SQL);
    }
};
