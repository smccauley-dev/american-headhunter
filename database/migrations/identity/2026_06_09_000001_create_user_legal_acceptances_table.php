<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'identity';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE user_legal_acceptances (
                id               UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                user_id          UUID NOT NULL,
                document_key     VARCHAR(100) NOT NULL,
                document_version INTEGER NOT NULL,
                accepted_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                ip_address       INET,
                user_agent       TEXT,
                context_type     VARCHAR(50),
                context_id       UUID,
                created_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),

                CONSTRAINT fk_ula_user FOREIGN KEY (user_id) REFERENCES users (id)
            );

            COMMENT ON COLUMN user_legal_acceptances.user_id         IS 'References DB 1 (Identity) users.id';
            COMMENT ON COLUMN user_legal_acceptances.document_key    IS 'Matches legal_documents.document_key in DB 12 (Platform)';
            COMMENT ON COLUMN user_legal_acceptances.document_version IS 'Version accepted — matches legal_documents.version in DB 12';
            COMMENT ON COLUMN user_legal_acceptances.context_id      IS 'References source entity e.g. lease_applications.id in DB 3 (Lease)';

            CREATE INDEX idx_user_legal_acceptances_user       ON user_legal_acceptances (user_id);
            CREATE INDEX idx_user_legal_acceptances_key        ON user_legal_acceptances (document_key);
            CREATE INDEX idx_user_legal_acceptances_user_key   ON user_legal_acceptances (user_id, document_key);
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS user_legal_acceptances CASCADE');
    }
};
