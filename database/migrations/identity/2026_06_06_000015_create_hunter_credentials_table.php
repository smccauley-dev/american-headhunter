<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'identity';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE hunter_credentials (
                id UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,

                -- Physical / mailing address
                address_line1 VARCHAR(255) NULL,
                address_line2 VARCHAR(255) NULL,
                city          VARCHAR(100) NULL,
                state_code    CHAR(2)      NULL,
                zip_code      VARCHAR(10)  NULL,

                -- Phone numbers (separate from users.phone primary number)
                home_phone    VARCHAR(30) NULL,
                cell_phone    VARCHAR(30) NULL,

                -- Emergency contact
                emergency_contact_name         VARCHAR(200) NULL,
                emergency_contact_phone        VARCHAR(30)  NULL,
                emergency_contact_relationship VARCHAR(50)  NULL,

                -- Medical
                medical_conditions TEXT NULL,

                -- Driver's license
                dl_number      VARCHAR(50) NULL,
                dl_state       CHAR(2)     NULL,
                dl_expiry      DATE        NULL,
                dl_document_id UUID        NULL,  -- References DB 11 (Documents) documents.id

                -- Hunting license
                hunting_license_number      VARCHAR(100) NULL,
                hunting_license_state       CHAR(2)      NULL,
                hunting_license_expiry      DATE         NULL,
                hunting_license_document_id UUID         NULL,  -- References DB 11 (Documents) documents.id

                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            );

            CREATE UNIQUE INDEX uq_hunter_credentials_user_id ON hunter_credentials (user_id);

            CREATE TRIGGER trg_hunter_credentials_updated_at
                BEFORE UPDATE ON hunter_credentials
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS hunter_credentials CASCADE;');
    }
};
