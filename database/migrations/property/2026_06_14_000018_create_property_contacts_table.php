<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'property';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<SQL
            CREATE TABLE property_contacts (
                id           UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                property_id  UUID         NOT NULL REFERENCES properties (id) ON DELETE CASCADE,
                contact_type VARCHAR(20)  NOT NULL
                                 CHECK (contact_type IN ('law_enforcement', 'game_warden', 'emergency', 'other')),
                label        VARCHAR(100) NULL,
                name         VARCHAR(150) NULL,
                organization VARCHAR(150) NULL,
                phone        VARCHAR(30)  NULL,
                email        VARCHAR(255) NULL,
                address      VARCHAR(255) NULL,
                notes        VARCHAR(500) NULL,
                sort_order   SMALLINT     NOT NULL DEFAULT 0,
                created_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                deleted_at   TIMESTAMPTZ  NULL
            );

            CREATE INDEX idx_property_contacts_property_id ON property_contacts (property_id);
            CREATE INDEX idx_property_contacts_sort        ON property_contacts (property_id, sort_order);
            CREATE INDEX idx_property_contacts_deleted_at  ON property_contacts (deleted_at) WHERE deleted_at IS NOT NULL;

            CREATE TRIGGER trg_property_contacts_updated_at
                BEFORE UPDATE ON property_contacts
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();

            COMMENT ON TABLE property_contacts IS
                'Field contacts for a property a hunter may need: local law enforcement, '
                'game warden, emergency, and custom contacts (e.g. a neighbor). Landowner '
                'and property managers are NOT stored here — they are derived from the owner '
                'account and property_managers via PropertyService::getContactDirectory().';

            COMMENT ON COLUMN property_contacts.label IS
                'Custom display label, used for contact_type = other (e.g. "Neighbor", "Nearest Hospital").';
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(
            'DROP TABLE IF EXISTS property_contacts;'
        );
    }
};
