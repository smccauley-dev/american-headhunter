<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'identity';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE guest_hunters (
                id            UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                owner_user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,

                first_name VARCHAR(100) NOT NULL,
                last_name  VARCHAR(100) NOT NULL,
                date_of_birth DATE NULL,

                email      VARCHAR(255) NULL,
                home_phone VARCHAR(30)  NULL,
                cell_phone VARCHAR(30)  NULL,

                address_line1 VARCHAR(255) NULL,
                address_line2 VARCHAR(255) NULL,
                city          VARCHAR(100) NULL,
                state_code    CHAR(2)      NULL,
                zip_code      VARCHAR(10)  NULL,

                emergency_contact_name         VARCHAR(200) NULL,
                emergency_contact_phone        VARCHAR(30)  NULL,
                emergency_contact_relationship VARCHAR(50)  NULL,

                medical_conditions TEXT NULL,

                dl_number      VARCHAR(50) NULL,
                dl_state       CHAR(2)     NULL,
                dl_expiry      DATE        NULL,
                dl_document_id UUID        NULL,  -- References DB 11 (Documents) documents.id

                hunting_license_number      VARCHAR(100) NULL,
                hunting_license_state       CHAR(2)      NULL,
                hunting_license_expiry      DATE         NULL,
                hunting_license_document_id UUID         NULL,  -- References DB 11 (Documents) documents.id

                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                deleted_at TIMESTAMPTZ NULL
            );

            CREATE INDEX idx_guest_hunters_owner_user_id ON guest_hunters (owner_user_id);

            CREATE TRIGGER trg_guest_hunters_updated_at
                BEFORE UPDATE ON guest_hunters
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS guest_hunters CASCADE;');
    }
};
