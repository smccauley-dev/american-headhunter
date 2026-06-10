<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'property';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            -- Append-only view tracking — ETL aggregates this into DB 8; do not query for reporting
            CREATE TABLE property_views (
                id         UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                listing_id UUID        NOT NULL REFERENCES property_listings (id) ON DELETE CASCADE,
                user_id    UUID        NULL,  -- References DB 1 (Identity) users.id — null for anonymous
                ip_address INET        NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            );

            CREATE INDEX idx_property_views_listing_id ON property_views (listing_id);
            CREATE INDEX idx_property_views_created_at ON property_views (created_at);
            CREATE INDEX idx_property_views_user_id    ON property_views (user_id) WHERE user_id IS NOT NULL;

            -- Hunter wishlist — one row per (user, listing) pair
            CREATE TABLE saved_properties (
                id         UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                user_id    UUID        NOT NULL,  -- References DB 1 (Identity) users.id
                listing_id UUID        NOT NULL REFERENCES property_listings (id) ON DELETE CASCADE,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            );

            CREATE UNIQUE INDEX uq_saved_properties_user_listing ON saved_properties (user_id, listing_id);
            CREATE        INDEX idx_saved_properties_user_id     ON saved_properties (user_id);
            CREATE        INDEX idx_saved_properties_listing_id  ON saved_properties (listing_id);
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(
            'DROP TABLE IF EXISTS saved_properties CASCADE;
             DROP TABLE IF EXISTS property_views CASCADE;'
        );
    }
};
