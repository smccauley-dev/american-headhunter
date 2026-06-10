<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE iot_devices (
                id                  UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                property_id         UUID NOT NULL,           -- References DB 2 (Property) properties.id
                device_type         VARCHAR(30) NOT NULL,
                provider            VARCHAR(50) NOT NULL,
                provider_device_id  VARCHAR(255) NOT NULL,
                name                VARCHAR(100) NOT NULL,
                status              VARCHAR(20) NOT NULL DEFAULT 'unknown',
                last_seen_at        TIMESTAMPTZ,
                config              JSONB NOT NULL DEFAULT '{}',
                created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                deleted_at          TIMESTAMPTZ,

                CONSTRAINT chk_iot_devices_type
                    CHECK (device_type IN ('smart_lock', 'trail_camera_cellular', 'weather_station')),
                CONSTRAINT chk_iot_devices_status
                    CHECK (status IN ('online', 'offline', 'unknown'))
            );

            CREATE INDEX idx_iot_devices_property_id ON iot_devices (property_id)
                WHERE deleted_at IS NULL;
            CREATE INDEX idx_iot_devices_provider ON iot_devices (provider, provider_device_id);
            CREATE INDEX idx_iot_devices_status ON iot_devices (status)
                WHERE deleted_at IS NULL;

            CREATE TRIGGER set_updated_at BEFORE UPDATE ON iot_devices
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS iot_devices CASCADE');
    }
};
