<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'property';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<SQL
            -- ── Status model: submitted → pending (under staff review) → approved | rejected ──
            -- 'submitted' is the new initial state (landowner submitted, not yet triaged).
            -- 'pending' becomes the in-review state staff set explicitly (and the seam a
            -- future AI verification step would write). Both are "open" / awaiting a decision.

            -- Re-key the "one open submission per property" index to cover both open states.
            DROP INDEX IF EXISTS uq_ownership_verifications_one_pending;

            -- Existing rows were 'pending' as the initial state — they have not been triaged,
            -- so they become 'submitted' under the new model.
            ALTER TABLE property_ownership_verifications
                DROP CONSTRAINT IF EXISTS property_ownership_verifications_status_check;

            UPDATE property_ownership_verifications SET status = 'submitted' WHERE status = 'pending';

            ALTER TABLE property_ownership_verifications
                ALTER COLUMN status SET DEFAULT 'submitted',
                ADD CONSTRAINT property_ownership_verifications_status_check
                    CHECK (status IN ('submitted', 'pending', 'approved', 'rejected'));

            CREATE UNIQUE INDEX uq_ownership_verifications_one_open
                ON property_ownership_verifications (property_id)
                WHERE status IN ('submitted', 'pending') AND deleted_at IS NULL;

            -- ── Internal staff review notes ──────────────────────────────────────────────
            -- Append-only log of staff questions/observations about an ownership submission.
            -- Shown only in the admin Ownership tab — never to the landowner. No RLS: like
            -- every property child table, access is scoped in the service layer / Filament
            -- runs as ah_system.
            CREATE TABLE property_ownership_review_notes (
                id              UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                property_id     UUID        NOT NULL REFERENCES properties (id) ON DELETE CASCADE,
                verification_id UUID        NULL REFERENCES property_ownership_verifications (id) ON DELETE SET NULL,
                author_user_id  UUID        NOT NULL,  -- References DB 1 (Identity) users.id (staff author)
                note            TEXT        NOT NULL,
                created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
            );

            CREATE INDEX idx_ownership_review_notes_property ON property_ownership_review_notes (property_id, created_at);

            COMMENT ON TABLE property_ownership_review_notes IS
                'Internal staff notes / questions about a property ownership-proof submission. '
                'Append-only; date-time and author stamped; shown only in the admin Ownership '
                'tab, never to the landowner.';
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<SQL
            DROP TABLE IF EXISTS property_ownership_review_notes;

            DROP INDEX IF EXISTS uq_ownership_verifications_one_open;

            ALTER TABLE property_ownership_verifications
                DROP CONSTRAINT IF EXISTS property_ownership_verifications_status_check;

            UPDATE property_ownership_verifications SET status = 'pending' WHERE status = 'submitted';

            ALTER TABLE property_ownership_verifications
                ALTER COLUMN status SET DEFAULT 'pending',
                ADD CONSTRAINT property_ownership_verifications_status_check
                    CHECK (status IN ('pending', 'approved', 'rejected'));

            CREATE UNIQUE INDEX uq_ownership_verifications_one_pending
                ON property_ownership_verifications (property_id)
                WHERE status = 'pending' AND deleted_at IS NULL;
        SQL);
    }
};
