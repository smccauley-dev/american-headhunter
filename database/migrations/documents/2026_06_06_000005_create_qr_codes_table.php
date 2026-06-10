<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'documents';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE qr_codes (
                id              UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                code_type       VARCHAR(20)  NOT NULL,
                target_id       UUID         NOT NULL,   -- The entity ID this QR resolves to
                target_type     VARCHAR(50)  NOT NULL,   -- e.g. 'lease', 'property', 'check_in'
                token           VARCHAR(100) NOT NULL,   -- Short token encoded in the QR image
                document_id     UUID         REFERENCES documents (id),  -- NULL until image generated
                expires_at      TIMESTAMPTZ,
                last_scanned_at TIMESTAMPTZ,
                scan_count      INT          NOT NULL DEFAULT 0,
                created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                deleted_at      TIMESTAMPTZ,

                CONSTRAINT uq_qr_codes_token UNIQUE (token),
                CONSTRAINT chk_qr_codes_type
                    CHECK (code_type IN ('check_in', 'property_access', 'harvest_report'))
            );

            CREATE INDEX idx_qr_codes_token ON qr_codes (token)
                WHERE deleted_at IS NULL;
            CREATE INDEX idx_qr_codes_target ON qr_codes (target_type, target_id)
                WHERE deleted_at IS NULL;
            CREATE INDEX idx_qr_codes_expires_at ON qr_codes (expires_at)
                WHERE expires_at IS NOT NULL AND deleted_at IS NULL;

            CREATE TRIGGER set_updated_at BEFORE UPDATE ON qr_codes
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS qr_codes CASCADE;');
    }
};
