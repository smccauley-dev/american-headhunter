<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'wildlife';

    public function up(): void
    {
        // Fishing catch log — parallel to harvest_logs for anglers (Phase 6 decision 2).
        // Same cross-DB / no-RLS / offline-dedup posture. length_inches + catch_and_release
        // are the fishing-specific fields; species_code is a separate fish enum.
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE fishing_harvest_logs (
                id                     UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                lease_id               UUID        NOT NULL,  -- References DB 3 (Lease) leases.id
                user_id                UUID        NOT NULL,  -- References DB 1 (Identity) users.id
                property_id            UUID        NOT NULL,  -- References DB 2 (Property) properties.id
                species_code           VARCHAR(50) NOT NULL
                                           CHECK (species_code IN (
                                               'largemouth_bass', 'smallmouth_bass', 'crappie', 'bluegill',
                                               'catfish', 'trout', 'walleye', 'pike', 'perch', 'carp',
                                               'striped_bass', 'other'
                                           )),
                catch_date             DATE        NOT NULL,
                catch_time             TIME        NULL,
                location_geospatial_id UUID        NULL,  -- References DB 13 (Geospatial) harvest_locations.id
                length_inches          NUMERIC(5,2) NULL,
                weight_lbs             NUMERIC(6,2) NULL,
                catch_and_release      BOOLEAN     NOT NULL DEFAULT false,
                field_photos           JSONB       NOT NULL DEFAULT '[]',  -- array of document_ids from DB 11
                notes                  TEXT        NULL,
                is_public              BOOLEAN     NOT NULL DEFAULT false,
                local_record_id        UUID        NULL,  -- offline capture dedup key (client-minted)
                created_at             TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at             TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                deleted_at             TIMESTAMPTZ NULL
            );

            CREATE INDEX idx_fishing_harvest_logs_lease_id     ON fishing_harvest_logs (lease_id);
            CREATE INDEX idx_fishing_harvest_logs_user_id      ON fishing_harvest_logs (user_id);
            CREATE INDEX idx_fishing_harvest_logs_property_id  ON fishing_harvest_logs (property_id);
            CREATE INDEX idx_fishing_harvest_logs_species_code ON fishing_harvest_logs (species_code);
            CREATE INDEX idx_fishing_harvest_logs_catch_date   ON fishing_harvest_logs (catch_date);
            CREATE INDEX idx_fishing_harvest_logs_deleted_at   ON fishing_harvest_logs (deleted_at) WHERE deleted_at IS NOT NULL;
            CREATE INDEX idx_fishing_harvest_logs_field_photos ON fishing_harvest_logs USING GIN (field_photos)
                WHERE jsonb_array_length(field_photos) > 0;
            CREATE UNIQUE INDEX uq_fishing_harvest_logs_user_local_record
                ON fishing_harvest_logs (user_id, local_record_id) WHERE local_record_id IS NOT NULL;

            CREATE TRIGGER trg_fishing_harvest_logs_updated_at
                BEFORE UPDATE ON fishing_harvest_logs
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS fishing_harvest_logs CASCADE');
    }
};
