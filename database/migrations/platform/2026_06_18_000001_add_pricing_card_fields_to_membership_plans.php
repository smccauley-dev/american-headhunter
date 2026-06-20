<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'platform';

    /**
     * Display-only fields for the public /pricing page cards. These describe how a
     * plan is presented (header image, accent, badge, featured), not what it costs
     * or grants — pricing lives on the immutable plan_versions and entitlements on
     * feature_entitlements — so they belong on the plan itself, not on a version.
     */
    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE membership_plans
                ADD COLUMN header_image_path VARCHAR(255),
                ADD COLUMN accent_color      VARCHAR(7),
                ADD COLUMN badge_label       VARCHAR(40),
                ADD COLUMN is_featured       BOOLEAN NOT NULL DEFAULT false;
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE membership_plans
                DROP COLUMN IF EXISTS header_image_path,
                DROP COLUMN IF EXISTS accent_color,
                DROP COLUMN IF EXISTS badge_label,
                DROP COLUMN IF EXISTS is_featured;
        SQL);
    }
};
