<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class CreateJobTables extends Migration
{
    protected $connection = 'identity';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<SQL
            CREATE TABLE IF NOT EXISTS failed_jobs (
                id          BIGSERIAL PRIMARY KEY,
                uuid        VARCHAR(255) NOT NULL UNIQUE,
                connection  TEXT         NOT NULL,
                queue       TEXT         NOT NULL,
                payload     TEXT         NOT NULL,
                exception   TEXT         NOT NULL,
                failed_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            );

            CREATE TABLE IF NOT EXISTS job_batches (
                id             VARCHAR(255) PRIMARY KEY,
                name           VARCHAR(255) NOT NULL,
                total_jobs     INTEGER      NOT NULL,
                pending_jobs   INTEGER      NOT NULL,
                failed_jobs    INTEGER      NOT NULL,
                failed_job_ids TEXT         NOT NULL,
                options        TEXT,
                cancelled_at   INTEGER,
                created_at     INTEGER      NOT NULL,
                finished_at    INTEGER
            );

            CREATE TABLE IF NOT EXISTS jobs (
                id           BIGSERIAL    PRIMARY KEY,
                queue        VARCHAR(255) NOT NULL,
                payload      TEXT         NOT NULL,
                attempts     SMALLINT     NOT NULL DEFAULT 0,
                reserved_at  INTEGER,
                available_at INTEGER      NOT NULL,
                created_at   INTEGER      NOT NULL
            );

            CREATE INDEX IF NOT EXISTS idx_jobs_queue ON jobs (queue);
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<SQL
            DROP TABLE IF EXISTS jobs;
            DROP TABLE IF EXISTS job_batches;
            DROP TABLE IF EXISTS failed_jobs;
        SQL);
    }
}
