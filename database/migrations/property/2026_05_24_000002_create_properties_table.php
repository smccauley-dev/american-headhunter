<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'property';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE properties (
                id                        UUID          NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                owner_user_id             UUID          NOT NULL,  -- References DB 1 (Identity) users.id
                title                     VARCHAR(255)  NOT NULL,
                slug                      VARCHAR(255)  NOT NULL,
                description               TEXT          NULL,
                status                    VARCHAR(20)   NOT NULL DEFAULT 'draft'
                                              CHECK (status IN ('draft', 'active', 'inactive', 'suspended')),
                state_code                CHAR(2)       NOT NULL,
                county                    VARCHAR(100)  NOT NULL,
                address_encrypted         TEXT          NULL,  -- pgp_sym_encrypt — physical address, gate road
                total_acres               NUMERIC(10,2) NOT NULL,
                huntable_acres            NUMERIC(10,2) NULL,
                boundary_geospatial_id    UUID          NULL,  -- References DB 13 (Geospatial) property_boundaries.id
                primary_photo_document_id UUID          NULL,  -- References DB 11 (Documents) documents.id
                created_at                TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
                updated_at                TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
                deleted_at                TIMESTAMPTZ   NULL
            );

            CREATE UNIQUE INDEX uq_properties_slug        ON properties (slug) WHERE deleted_at IS NULL;
            CREATE        INDEX idx_properties_owner      ON properties (owner_user_id);
            CREATE        INDEX idx_properties_status     ON properties (status);
            CREATE        INDEX idx_properties_state      ON properties (state_code);
            CREATE        INDEX idx_properties_county     ON properties (state_code, county);
            CREATE        INDEX idx_properties_deleted_at ON properties (deleted_at) WHERE deleted_at IS NOT NULL;

            CREATE TRIGGER trg_properties_updated_at
                BEFORE UPDATE ON properties
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS properties CASCADE');
    }
};
