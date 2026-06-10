<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'identity';

    public function up(): void
    {
        // Recovery codes moved to user_recovery_codes (account-level, bcrypt-hashed).
        // This column is dead and its name ("encrypted backup codes") is a trap for
        // anyone who reads the schema and assumes it is still the live storage path.
        DB::connection($this->connection)->unprepared(<<<SQL
            ALTER TABLE mfa_configurations DROP COLUMN IF EXISTS backup_codes_encrypted;
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<SQL
            ALTER TABLE mfa_configurations ADD COLUMN IF NOT EXISTS backup_codes_encrypted TEXT NULL;
        SQL);
    }
};
