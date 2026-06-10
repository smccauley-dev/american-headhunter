<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'identity';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<SQL
            CREATE TABLE user_admin_notes (
                id               UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                user_id          UUID        NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                author_user_id   UUID        NOT NULL REFERENCES users (id),
                note             TEXT        NOT NULL,
                created_at       TIMESTAMPTZ NOT NULL DEFAULT NOW()
                -- Append-only: no updated_at, no deleted_at
            );

            CREATE INDEX idx_user_admin_notes_user_id    ON user_admin_notes (user_id);
            CREATE INDEX idx_user_admin_notes_author     ON user_admin_notes (author_user_id);
            CREATE INDEX idx_user_admin_notes_created_at ON user_admin_notes (user_id, created_at DESC);

            COMMENT ON TABLE user_admin_notes IS
                'Internal staff-only notes per user. Append-only — never updated or deleted. '
                'Platform users never see these notes.';

            COMMENT ON COLUMN user_admin_notes.author_user_id IS
                'References users.id — the staff member who wrote the note';
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(
            'DROP TABLE IF EXISTS user_admin_notes;'
        );
    }
};
