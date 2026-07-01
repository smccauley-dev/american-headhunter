<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'wildlife';

    public function up(): void
    {
        // Non-harvest observations. local_record_id + partial unique added for offline
        // capture dedup (same posture as harvest_logs).
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE wildlife_sightings (
                id                     UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                lease_id               UUID        NOT NULL,  -- References DB 3 (Lease) leases.id
                user_id                UUID        NOT NULL,  -- References DB 1 (Identity) users.id
                property_id            UUID        NOT NULL,  -- References DB 2 (Property) properties.id
                species_code           VARCHAR(50) NOT NULL
                                           CHECK (species_code IN (
                                               'whitetail_deer', 'mule_deer', 'turkey', 'waterfowl', 'dove',
                                               'hog', 'elk', 'bear', 'antelope', 'pheasant', 'quail',
                                               'rabbit', 'squirrel', 'coyote', 'other', 'unknown'
                                           )),
                sighting_date          DATE        NOT NULL,
                sighting_time          TIME        NULL,
                count                  SMALLINT    NOT NULL DEFAULT 1,
                location_geospatial_id UUID        NULL,  -- References DB 13 (Geospatial) harvest_locations.id
                notes                  TEXT        NULL,
                photo_document_ids     JSONB       NOT NULL DEFAULT '[]',  -- array of document_ids from DB 11
                local_record_id        UUID        NULL,  -- offline capture dedup key (client-minted)
                created_at             TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at             TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                deleted_at             TIMESTAMPTZ NULL
            );

            CREATE INDEX idx_wildlife_sightings_lease_id    ON wildlife_sightings (lease_id);
            CREATE INDEX idx_wildlife_sightings_user_id     ON wildlife_sightings (user_id);
            CREATE INDEX idx_wildlife_sightings_property_id ON wildlife_sightings (property_id);
            CREATE INDEX idx_wildlife_sightings_species     ON wildlife_sightings (species_code);
            CREATE INDEX idx_wildlife_sightings_date        ON wildlife_sightings (sighting_date);
            CREATE INDEX idx_wildlife_sightings_deleted_at  ON wildlife_sightings (deleted_at) WHERE deleted_at IS NOT NULL;
            CREATE UNIQUE INDEX uq_wildlife_sightings_user_local_record
                ON wildlife_sightings (user_id, local_record_id) WHERE local_record_id IS NOT NULL;

            CREATE TRIGGER trg_wildlife_sightings_updated_at
                BEFORE UPDATE ON wildlife_sightings
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS wildlife_sightings CASCADE');
    }
};
