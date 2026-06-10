<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'property';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE property_listings (
                id               UUID          NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                property_id      UUID          NOT NULL REFERENCES properties (id) ON DELETE CASCADE,
                listing_type     VARCHAR(20)   NOT NULL
                                     CHECK (listing_type IN ('annual_lease', 'seasonal_lease', 'day_hunt', 'auction')),
                status           VARCHAR(20)   NOT NULL DEFAULT 'draft'
                                     CHECK (status IN ('draft', 'active', 'sold_out', 'expired', 'archived')),
                season_start     DATE          NULL,
                season_end       DATE          NULL,
                min_hunters      SMALLINT      NULL,
                max_hunters      SMALLINT      NOT NULL DEFAULT 1,
                price_per_hunter NUMERIC(10,2) NULL,
                price_total      NUMERIC(10,2) NULL,
                deposit_amount   NUMERIC(10,2) NULL,
                deposit_percent  SMALLINT      NULL CHECK (deposit_percent BETWEEN 0 AND 100),
                auto_renew       BOOLEAN       NOT NULL DEFAULT false,
                visibility       VARCHAR(20)   NOT NULL DEFAULT 'public'
                                     CHECK (visibility IN ('public', 'members_only', 'invite_only')),
                created_at       TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
                updated_at       TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
                deleted_at       TIMESTAMPTZ   NULL
            );

            CREATE INDEX idx_property_listings_property_id ON property_listings (property_id);
            CREATE INDEX idx_property_listings_status      ON property_listings (status);
            CREATE INDEX idx_property_listings_type        ON property_listings (listing_type);
            CREATE INDEX idx_property_listings_season      ON property_listings (season_start, season_end);
            CREATE INDEX idx_property_listings_deleted_at  ON property_listings (deleted_at) WHERE deleted_at IS NOT NULL;

            CREATE TRIGGER trg_property_listings_updated_at
                BEFORE UPDATE ON property_listings
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS property_listings CASCADE');
    }
};
