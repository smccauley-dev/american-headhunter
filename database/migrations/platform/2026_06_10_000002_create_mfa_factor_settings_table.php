<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE mfa_factor_settings (
                factor      VARCHAR(10)  NOT NULL PRIMARY KEY
                                CHECK (factor IN ('totp', 'sms', 'email')),
                is_enabled  BOOLEAN      NOT NULL DEFAULT true,
                updated_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            );

            CREATE TRIGGER set_updated_at BEFORE UPDATE ON mfa_factor_settings
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();

            -- SMS disabled by default: no real provider is wired.
            -- Enable only after a real SMS driver replaces StubSmsDriver
            -- (see DEPLOYMENT.md §1a).
            INSERT INTO mfa_factor_settings (factor, is_enabled) VALUES
                ('totp',  true),
                ('sms',   false),
                ('email', true);
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            DROP TABLE IF EXISTS mfa_factor_settings;
        SQL);
    }
};
