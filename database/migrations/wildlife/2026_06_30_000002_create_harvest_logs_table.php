<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'wildlife';

    public function up(): void
    {
        // DB 5 has NO row-level security — standing is enforced at the service
        // layer (WildlifeAccess / CheckInService). All property/lease/user/photo
        // references are bare cross-DB UUIDs. The GPS point lives in DB 13
        // (harvest_locations); this table keeps only its id.
        //
        // local_record_id: client-minted UUID for offline capture. The partial
        // unique index makes an offline replay idempotent (one row + one quota
        // increment) without constraining server-authored rows (NULL).
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE harvest_logs (
                id                     UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                lease_id               UUID        NOT NULL,  -- References DB 3 (Lease) leases.id
                user_id                UUID        NOT NULL,  -- References DB 1 (Identity) users.id
                property_id            UUID        NOT NULL,  -- References DB 2 (Property) properties.id
                species_code           VARCHAR(50) NOT NULL
                                           CHECK (species_code IN (
                                               'whitetail_deer', 'mule_deer', 'turkey', 'waterfowl', 'dove',
                                               'hog', 'elk', 'bear', 'antelope', 'pheasant', 'quail',
                                               'rabbit', 'squirrel', 'coyote', 'other'
                                           )),
                harvest_date           DATE        NOT NULL,
                harvest_time           TIME        NULL,
                location_geospatial_id UUID        NULL,  -- References DB 13 (Geospatial) harvest_locations.id
                weapon_type            VARCHAR(20) NOT NULL
                                           CHECK (weapon_type IN ('bow', 'rifle', 'shotgun', 'muzzleloader', 'pistol', 'other')),
                antler_score           NUMERIC(6,2) NULL,
                weight_lbs             NUMERIC(6,2) NULL,
                age_estimate           VARCHAR(20) NULL,
                field_photos           JSONB       NOT NULL DEFAULT '[]',  -- array of document_ids from DB 11
                notes                  TEXT        NULL,
                is_public              BOOLEAN     NOT NULL DEFAULT false,
                ai_score               NUMERIC(6,2) NULL,
                ai_scored_at           TIMESTAMPTZ NULL,
                local_record_id        UUID        NULL,  -- offline capture dedup key (client-minted)
                created_at             TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at             TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                deleted_at             TIMESTAMPTZ NULL
            );

            CREATE INDEX idx_harvest_logs_lease_id     ON harvest_logs (lease_id);
            CREATE INDEX idx_harvest_logs_user_id      ON harvest_logs (user_id);
            CREATE INDEX idx_harvest_logs_property_id  ON harvest_logs (property_id);
            CREATE INDEX idx_harvest_logs_species_code ON harvest_logs (species_code);
            CREATE INDEX idx_harvest_logs_harvest_date ON harvest_logs (harvest_date);
            CREATE INDEX idx_harvest_logs_deleted_at   ON harvest_logs (deleted_at) WHERE deleted_at IS NOT NULL;
            CREATE INDEX idx_harvest_logs_field_photos ON harvest_logs USING GIN (field_photos)
                WHERE jsonb_array_length(field_photos) > 0;
            CREATE UNIQUE INDEX uq_harvest_logs_user_local_record
                ON harvest_logs (user_id, local_record_id) WHERE local_record_id IS NOT NULL;

            CREATE TRIGGER trg_harvest_logs_updated_at
                BEFORE UPDATE ON harvest_logs
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS harvest_logs CASCADE');
    }
};
