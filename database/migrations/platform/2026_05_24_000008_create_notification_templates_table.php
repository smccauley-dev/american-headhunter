<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE notification_templates (
                id              UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                slug            VARCHAR(100) NOT NULL,
                display_name    VARCHAR(200) NOT NULL,
                description     TEXT,
                channel         VARCHAR(20) NOT NULL DEFAULT 'email',
                variable_schema JSONB NOT NULL DEFAULT '{}',
                is_active       BOOLEAN NOT NULL DEFAULT true,
                created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),

                CONSTRAINT uq_notification_templates_slug UNIQUE (slug),
                CONSTRAINT chk_notification_templates_channel
                    CHECK (channel IN ('email', 'push', 'sms'))
            );

            CREATE INDEX idx_notification_templates_slug ON notification_templates (slug);
            CREATE INDEX idx_notification_templates_channel ON notification_templates (channel)
                WHERE is_active = true;

            CREATE TRIGGER set_updated_at BEFORE UPDATE ON notification_templates
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();

            CREATE TABLE notification_template_versions (
                id                  UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                template_id         UUID NOT NULL REFERENCES notification_templates (id),
                version_number      SMALLINT NOT NULL,
                status              VARCHAR(20) NOT NULL DEFAULT 'draft',
                subject             VARCHAR(500),
                html_body           TEXT,
                text_body           TEXT,
                metadata            JSONB NOT NULL DEFAULT '{}',
                promoted_at         TIMESTAMPTZ,
                promoted_by_user_id UUID,
                archived_at         TIMESTAMPTZ,
                notes               TEXT,
                created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),

                CONSTRAINT uq_notification_template_versions UNIQUE (template_id, version_number),
                CONSTRAINT chk_notification_template_versions_status
                    CHECK (status IN ('draft', 'review', 'production', 'archived'))
            );

            CREATE INDEX idx_notification_template_versions_template ON notification_template_versions (template_id);
            CREATE INDEX idx_notification_template_versions_production ON notification_template_versions (template_id, status)
                WHERE status = 'production';

            INSERT INTO notification_templates (slug, display_name, description, channel, variable_schema) VALUES
                ('email_verify',
                 'Email Verification',
                 'Sent when a new user registers and needs to verify their email address',
                 'email',
                 '{"first_name": "User first name (may be empty)", "verification_url": "The full verification URL"}'
                ),
                ('password_reset',
                 'Password Reset',
                 'Sent when a user requests a password reset link',
                 'email',
                 '{"first_name": "User first name (may be empty)", "reset_url": "The full password reset URL"}'
                ),
                ('lease_approved',
                 'Lease Approved',
                 'Sent to a hunter when their lease application is approved',
                 'email',
                 '{"first_name": "Hunter first name", "property_name": "Property name", "lease_start_date": "Lease start date", "portal_url": "Link to member portal"}'
                ),
                ('lease_denied',
                 'Lease Denied',
                 'Sent to a hunter when their lease application is denied',
                 'email',
                 '{"first_name": "Hunter first name", "property_name": "Property name"}'
                ),
                ('payment_receipt',
                 'Payment Receipt',
                 'Sent after a successful payment is processed',
                 'email',
                 '{"first_name": "User first name", "amount": "Formatted amount", "description": "Payment description", "receipt_url": "Stripe receipt URL"}'
                ),
                ('sos_alert',
                 'SOS Alert',
                 'Sent to emergency contacts and staff when an SOS event is triggered',
                 'email',
                 '{"hunter_name": "Hunter full name", "location_description": "Last known location", "sos_time": "Time SOS was triggered", "dashboard_url": "Admin dashboard link"}'
                );
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            DROP TABLE IF EXISTS notification_template_versions CASCADE;
            DROP TABLE IF EXISTS notification_templates CASCADE;
        SQL);
    }
};
