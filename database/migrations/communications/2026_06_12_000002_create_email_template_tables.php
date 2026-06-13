<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'communications';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE email_templates (
                id            UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                template_key  VARCHAR(100) NOT NULL,  -- e.g. 'auth.password_reset' — stable lookup key used by Mailables
                name          VARCHAR(150) NOT NULL,
                category      VARCHAR(20)  NOT NULL DEFAULT 'custom'
                                  CHECK (category IN ('system', 'custom')),
                owner_type    VARCHAR(30)  NULL,      -- NULL = platform-owned. Future: 'advertiser', 'outfitter', 'charter', 'club', 'corporate'
                owner_user_id UUID         NULL,      -- References DB 1 (Identity) users.id
                created_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                deleted_at    TIMESTAMPTZ  NULL
            );

            COMMENT ON COLUMN email_templates.template_key IS 'Stable key used by application code to look up the template. Unique among live rows.';
            COMMENT ON COLUMN email_templates.category     IS 'system = wired to application code, cannot be deleted. custom = admin-created.';
            COMMENT ON COLUMN email_templates.owner_type   IS 'NULL = platform-owned. Reserved for Phase 2 third-party senders (advertiser, outfitter, charter, club, corporate).';

            CREATE UNIQUE INDEX uq_email_templates_template_key
                ON email_templates (template_key) WHERE deleted_at IS NULL;
            CREATE INDEX idx_email_templates_owner ON email_templates (owner_type, owner_user_id);

            CREATE TRIGGER set_updated_at BEFORE UPDATE ON email_templates
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();

            CREATE TABLE email_template_versions (
                id                 UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                template_id        UUID         NOT NULL REFERENCES email_templates (id) ON DELETE CASCADE,
                version_number     INTEGER      NOT NULL,
                subject            VARCHAR(255) NOT NULL,
                html_body          TEXT         NULL,      -- NULL for plain-text-only emails
                text_body          TEXT         NULL,      -- plain-text alternative / fallback
                status             VARCHAR(10)  NOT NULL DEFAULT 'draft'
                                       CHECK (status IN ('draft', 'active', 'archived')),
                notes              VARCHAR(255) NULL,      -- admin note: what changed in this version
                created_by_user_id UUID         NULL,      -- References DB 1 (Identity) users.id
                created_at         TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at         TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                CONSTRAINT chk_email_template_versions_has_body
                    CHECK (html_body IS NOT NULL OR text_body IS NOT NULL)
            );

            COMMENT ON COLUMN email_template_versions.status IS 'Exactly one active version per template (enforced by partial unique index and EmailTemplateService).';

            CREATE UNIQUE INDEX uq_email_template_versions_number
                ON email_template_versions (template_id, version_number);
            CREATE UNIQUE INDEX uq_email_template_versions_active
                ON email_template_versions (template_id) WHERE status = 'active';
            CREATE INDEX idx_email_template_versions_template_id
                ON email_template_versions (template_id);

            CREATE TRIGGER set_updated_at BEFORE UPDATE ON email_template_versions
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            DROP TABLE IF EXISTS email_template_versions CASCADE;
            DROP TABLE IF EXISTS email_templates CASCADE;
        SQL);
    }
};
