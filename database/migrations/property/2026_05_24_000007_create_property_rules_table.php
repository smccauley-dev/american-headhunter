<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'property';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE property_rules (
                id          UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                property_id UUID        NOT NULL REFERENCES properties (id) ON DELETE CASCADE,
                rule_text   TEXT        NOT NULL,
                sort_order  SMALLINT    NOT NULL DEFAULT 0,
                created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
            );

            CREATE INDEX idx_property_rules_property_id ON property_rules (property_id);

            CREATE TRIGGER trg_property_rules_updated_at
                BEFORE UPDATE ON property_rules
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS property_rules CASCADE');
    }
};
