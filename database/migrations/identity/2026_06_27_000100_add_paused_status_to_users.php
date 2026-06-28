<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5.4 (Slice 2) — add a 'paused' user status.
 *
 * When a promotional period whose on_expiration is 'pause_account' lapses,
 * ExpirePromotionClaims puts the account into a paused state: the user keeps
 * their record but loses portal access until they start a paid subscription
 * (which reactivates them). 'paused' is distinct from the moderation states
 * 'suspended'/'banned' — it is a billing condition, not a punishment, and the
 * login path messages it differently.
 */
return new class extends Migration
{
    protected $connection = 'identity';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE users DROP CONSTRAINT users_status_check;
            ALTER TABLE users ADD CONSTRAINT users_status_check
                CHECK (status IN ('active', 'suspended', 'banned', 'pending_verification', 'paused'));
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            UPDATE users SET status = 'suspended' WHERE status = 'paused';
            ALTER TABLE users DROP CONSTRAINT users_status_check;
            ALTER TABLE users ADD CONSTRAINT users_status_check
                CHECK (status IN ('active', 'suspended', 'banned', 'pending_verification'));
        SQL);
    }
};
