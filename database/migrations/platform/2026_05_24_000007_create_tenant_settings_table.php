<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE tenant_settings (
                id          UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                key         VARCHAR(100) NOT NULL,
                value       JSONB NOT NULL,
                description TEXT,
                is_public   BOOLEAN NOT NULL DEFAULT false,
                created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),

                CONSTRAINT uq_tenant_settings_key UNIQUE (key)
            );

            CREATE INDEX idx_tenant_settings_key ON tenant_settings (key);

            CREATE TRIGGER set_updated_at BEFORE UPDATE ON tenant_settings
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();

            -- Default platform settings (these override config/platform.php in Phase 3+)
            INSERT INTO tenant_settings (key, value, description, is_public) VALUES
                ('legal.tos_url',          '"/terms"',           'Terms of Service URL', true),
                ('legal.privacy_url',      '"/privacy"',         'Privacy Policy URL', true),
                ('legal.ccpa_url',         '"/privacy#ccpa"',    'CCPA Notice URL', true),
                ('auth.logout_redirect',   '"/"',                'URL to redirect after logout', false),
                ('platform.name',          '"American Headhunter"', 'Platform display name', true),
                ('platform.support_email', '"support@americanheadhunter.com"', 'Support email address', true),
                ('platform.sos_phone',     '"+18005550000"',     'SOS emergency phone number', true),
                ('billing.currency',       '"USD"',              'Default billing currency', true),
                ('billing.founding_landowner_slots', '500',      'Total Founding Landowner promotion slots', false);
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS tenant_settings CASCADE');
    }
};
