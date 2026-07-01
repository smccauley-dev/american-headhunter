<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'wildlife';

    public function up(): void
    {
        // chk_harvest_quotas_not_exceeded is the DB backstop behind the atomic
        // QuotaService increment (UPDATE ... WHERE current_harvest < max_harvest RETURNING *).
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE harvest_quotas (
                id               UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                property_id      UUID        NOT NULL,  -- References DB 2 (Property) properties.id
                lease_id         UUID        NULL,      -- References DB 3 (Lease) leases.id — null for property-wide quota
                species_code     VARCHAR(50) NOT NULL,
                season_year      SMALLINT    NOT NULL,
                max_harvest      SMALLINT    NOT NULL,
                current_harvest  SMALLINT    NOT NULL DEFAULT 0,
                created_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),

                CONSTRAINT chk_harvest_quotas_counts CHECK (current_harvest >= 0 AND max_harvest > 0),
                CONSTRAINT chk_harvest_quotas_not_exceeded CHECK (current_harvest <= max_harvest)
            );

            CREATE UNIQUE INDEX uq_harvest_quotas_property_lease_species_year
                ON harvest_quotas (property_id, COALESCE(lease_id, '00000000-0000-0000-0000-000000000000'::UUID), species_code, season_year);
            CREATE INDEX idx_harvest_quotas_property_id ON harvest_quotas (property_id);
            CREATE INDEX idx_harvest_quotas_lease_id    ON harvest_quotas (lease_id) WHERE lease_id IS NOT NULL;

            CREATE TRIGGER trg_harvest_quotas_updated_at
                BEFORE UPDATE ON harvest_quotas
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS harvest_quotas CASCADE');
    }
};
