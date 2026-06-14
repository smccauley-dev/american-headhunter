<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'property';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<SQL
            ALTER TABLE property_managers
                ADD COLUMN IF NOT EXISTS is_field_contact BOOLEAN NOT NULL DEFAULT FALSE;

            COMMENT ON COLUMN property_managers.is_field_contact IS
                'When true, this manager is explicitly shown to active lessees as a field '
                'contact in the property contact directory. Managers are opt-in only — they '
                'are not surfaced to hunters unless an admin adds them via Add Manager Contact.';
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(
            'ALTER TABLE property_managers DROP COLUMN IF EXISTS is_field_contact;'
        );
    }
};
