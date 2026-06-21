<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'identity';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE users
                ADD COLUMN intended_plan_key VARCHAR(80) NULL;

            COMMENT ON COLUMN users.intended_plan_key IS
                'Membership plan the user chose before signing up (carried from the '
                'pricing page through get-started/register). References DB 12 '
                '(Platform) membership_plans.plan_key — no FK, cross-DB. Consumed and '
                'cleared at first post-verification login: a paid plan routes the user '
                'to checkout, a free plan needs no action.';
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE users DROP COLUMN IF EXISTS intended_plan_key;
        SQL);
    }
};
