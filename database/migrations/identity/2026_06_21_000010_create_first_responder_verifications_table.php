<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'identity';

    public function up(): void
    {
        // Mirrors veteran_verifications (2026_05_24_000009). Kept as a parallel
        // table per the chosen data model: First Responder verification tracks the
        // same lifecycle (method → pending → approved/rejected) but is a distinct
        // benefit with its own promotion and admin queue. The upload method is a
        // generic credential (department ID, badge, certification) rather than a
        // DD-214; ID.me also offers a first-responder verification group.
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE first_responder_verifications (
                id                   UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                user_id              UUID         NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                method               VARCHAR(30)  NOT NULL
                                         CHECK (method IN ('id_me', 'credential_upload')),
                status               VARCHAR(20)  NOT NULL
                                         CHECK (status IN ('pending', 'approved', 'rejected')),
                document_id          UUID         NULL,
                id_me_uuid           VARCHAR(255) NULL,
                verified_at          TIMESTAMPTZ  NULL,
                reviewed_by_user_id  UUID         NULL REFERENCES users (id) ON DELETE SET NULL,
                created_at           TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at           TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            );

            CREATE INDEX idx_first_responder_verifications_user_id ON first_responder_verifications (user_id);
            CREATE INDEX idx_first_responder_verifications_status  ON first_responder_verifications (status);

            CREATE TRIGGER set_updated_at_first_responder_verifications
                BEFORE UPDATE ON first_responder_verifications
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            DROP TABLE IF EXISTS first_responder_verifications CASCADE;
        SQL);
    }
};
