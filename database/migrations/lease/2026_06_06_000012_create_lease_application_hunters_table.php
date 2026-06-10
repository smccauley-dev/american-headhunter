<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'lease';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE lease_application_hunters (
                id             UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                application_id UUID        NOT NULL REFERENCES lease_applications(id) ON DELETE CASCADE,
                hunter_type    VARCHAR(10) NOT NULL DEFAULT 'primary'
                                   CHECK (hunter_type IN ('primary', 'guest')),

                -- Cross-DB references — no FK constraints, no joins across connections
                user_id         UUID NULL,  -- For primary: References DB 1 (Identity) users.id
                guest_hunter_id UUID NULL,  -- For guests: References DB 1 (Identity) guest_hunters.id

                -- Immutable snapshot of hunter info at time of application submission
                first_name    VARCHAR(100) NOT NULL,
                last_name     VARCHAR(100) NOT NULL,
                date_of_birth DATE         NULL,
                is_minor      BOOLEAN      NOT NULL DEFAULT false,

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

                -- Driver's license snapshot
                dl_number            VARCHAR(50) NULL,
                dl_state             CHAR(2)     NULL,
                dl_expiry            DATE        NULL,
                dl_document_id       UUID        NULL,  -- References DB 11 (Documents) documents.id
                dl_confirmed_current BOOLEAN     NOT NULL DEFAULT false,

                -- Hunting license snapshot
                hunting_license_number            VARCHAR(100) NULL,
                hunting_license_state             CHAR(2)      NULL,
                hunting_license_expiry            DATE         NULL,
                hunting_license_document_id       UUID         NULL,  -- References DB 11 (Documents) documents.id
                hunting_license_confirmed_current BOOLEAN      NOT NULL DEFAULT false,

                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                -- No updated_at or deleted_at — this is an immutable application snapshot
            );

            CREATE INDEX idx_lease_application_hunters_application_id
                ON lease_application_hunters (application_id);
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS lease_application_hunters CASCADE;');
    }
};
