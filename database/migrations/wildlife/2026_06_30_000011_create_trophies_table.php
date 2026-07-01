<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'wildlife';

    public function up(): void
    {
        // Dedicated trophy scoring record (Phase 6 decision 2) — separate from the raw
        // harvest_logs.antler_score so a harvest can carry a formal, official-scored entry
        // under a named scoring system. Same-DB FK to harvest_logs (both DB 5). scored_by
        // is a cross-DB user ref (the official scorer, may differ from the hunter).
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE trophies (
                id              UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                harvest_log_id  UUID         NOT NULL REFERENCES harvest_logs (id) ON DELETE CASCADE,
                scoring_system  VARCHAR(20)  NOT NULL
                                    CHECK (scoring_system IN ('boone_crockett', 'pope_young', 'sci', 'buckmasters')),
                gross_score     NUMERIC(6,2) NULL,
                net_score       NUMERIC(6,2) NULL,
                is_official     BOOLEAN      NOT NULL DEFAULT false,
                scored_by       UUID         NULL,  -- References DB 1 (Identity) users.id — official scorer
                scored_at       TIMESTAMPTZ  NULL,
                notes           TEXT         NULL,
                created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                deleted_at      TIMESTAMPTZ  NULL
            );

            CREATE INDEX idx_trophies_harvest_log_id ON trophies (harvest_log_id);
            CREATE INDEX idx_trophies_scoring_system ON trophies (scoring_system);
            CREATE INDEX idx_trophies_deleted_at     ON trophies (deleted_at) WHERE deleted_at IS NOT NULL;
            CREATE UNIQUE INDEX uq_trophies_harvest_scoring_system
                ON trophies (harvest_log_id, scoring_system) WHERE deleted_at IS NULL;

            CREATE TRIGGER trg_trophies_updated_at
                BEFORE UPDATE ON trophies
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS trophies CASCADE');
    }
};
