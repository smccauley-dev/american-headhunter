<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'property';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<SQL
            CREATE TABLE property_amenity_offerings (
                property_id UUID NOT NULL REFERENCES properties (id) ON DELETE CASCADE,
                amenity_id  UUID NOT NULL REFERENCES property_amenities (id) ON DELETE CASCADE,
                PRIMARY KEY (property_id, amenity_id)
            );

            CREATE INDEX idx_pao_property_id ON property_amenity_offerings (property_id);
            CREATE INDEX idx_pao_amenity_id  ON property_amenity_offerings (amenity_id);
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(
            'DROP TABLE IF EXISTS property_amenity_offerings;'
        );
    }
};
