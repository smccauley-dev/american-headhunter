<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'documents';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<SQL
            ALTER TABLE esignature_signers
                ADD COLUMN IF NOT EXISTS provider_signer_id VARCHAR(255) NULL;

            COMMENT ON COLUMN esignature_signers.provider_signer_id
                IS 'Dropbox Sign signature_id for this signer — NULL for in_platform provider';

            CREATE INDEX IF NOT EXISTS idx_esignature_signers_provider_signer_id
                ON esignature_signers (provider_signer_id)
                WHERE provider_signer_id IS NOT NULL;
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<SQL
            DROP INDEX IF EXISTS idx_esignature_signers_provider_signer_id;
            ALTER TABLE esignature_signers DROP COLUMN IF EXISTS provider_signer_id;
        SQL);
    }
};
