<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE feature_entitlements (
                id                  UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                plan_id             UUID NOT NULL REFERENCES membership_plans (id),
                feature_key         VARCHAR(100) NOT NULL,

                feature_type        VARCHAR(20) NOT NULL DEFAULT 'boolean',
                bool_value          BOOLEAN,
                int_value           INTEGER,
                string_value        VARCHAR(255),
                json_value          JSONB,

                display_label       VARCHAR(255),
                display_description TEXT,
                display_order       INTEGER NOT NULL DEFAULT 0,
                show_on_pricing     BOOLEAN NOT NULL DEFAULT true,

                created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),

                CONSTRAINT uq_feature_entitlements_plan_key UNIQUE (plan_id, feature_key),
                CONSTRAINT chk_feature_entitlements_type
                    CHECK (feature_type IN ('boolean', 'integer', 'string', 'json'))
            );

            CREATE INDEX idx_feature_entitlements_plan_id ON feature_entitlements (plan_id);
            CREATE INDEX idx_feature_entitlements_key ON feature_entitlements (feature_key);

            CREATE TRIGGER set_updated_at BEFORE UPDATE ON feature_entitlements
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
        SQL);

        // Seed data uses PHP to avoid PostgreSQL UNION type inference issues with mixed NULL columns
        $platform = DB::connection($this->connection);

        $plans = $platform->table('membership_plans')
            ->pluck('id', 'plan_key');

        $rows = [];

        // Hunter Scout (free)
        if (isset($plans['hunter_scout'])) {
            $id = $plans['hunter_scout'];
            $rows = array_merge($rows, [
                [$id, 'saved_searches_limit',          'integer', null,  3,    null,        'Up to 3 saved searches',              10],
                [$id, 'lease_applications_per_season', 'integer', null,  5,    null,        'Up to 5 lease applications/season',   20],
                [$id, 'trail_camera_integration',      'boolean', false, null, null,        'Trail camera integration',            30],
                [$id, 'digital_id_card',               'boolean', false, null, null,        'Digital hunter ID card',              40],
                [$id, 'background_checks_per_year',    'integer', null,  0,    null,        'Background check',                    50],
                [$id, 'early_listing_access_hours',    'integer', null,  0,    null,        'Early listing access',                60],
            ]);
        }

        // Hunter Sportsman
        if (isset($plans['hunter_sportsman'])) {
            $id = $plans['hunter_sportsman'];
            $rows = array_merge($rows, [
                [$id, 'saved_searches_limit',          'integer', null,  -1,   null,        'Unlimited saved searches',            10],
                [$id, 'lease_applications_per_season', 'integer', null,  -1,   null,        'Unlimited lease applications',        20],
                [$id, 'trail_camera_integration',      'boolean', true,  null, null,        'Trail camera integration',            30],
                [$id, 'digital_id_card',               'boolean', true,  null, null,        'Digital hunter ID card',              40],
                [$id, 'background_checks_per_year',    'integer', null,  1,    null,        '1 background check/year included',    50],
                [$id, 'early_listing_access_hours',    'integer', null,  24,   null,        '24-hour early listing access',        60],
                [$id, 'trust_badge_level',             'string',  null,  null, 'enhanced',  'Enhanced trust badge',                70],
            ]);
        }

        // Hunter Outfitter
        if (isset($plans['hunter_outfitter'])) {
            $id = $plans['hunter_outfitter'];
            $rows = array_merge($rows, [
                [$id, 'saved_searches_limit',          'integer', null,  -1,   null,        'Unlimited saved searches',            10],
                [$id, 'lease_applications_per_season', 'integer', null,  -1,   null,        'Unlimited lease applications',        20],
                [$id, 'trail_camera_integration',      'boolean', true,  null, null,        'Trail camera integration',            30],
                [$id, 'digital_id_card',               'boolean', true,  null, null,        'Digital hunter ID card',              40],
                [$id, 'background_checks_per_year',    'integer', null,  3,    null,        '3 background checks/year',            50],
                [$id, 'early_listing_access_hours',    'integer', null,  48,   null,        '48-hour early listing access',        60],
                [$id, 'trust_badge_level',             'string',  null,  null, 'premium',   'Premium trust badge',                 70],
                [$id, 'concierge_messaging',           'boolean', true,  null, null,        'Concierge messaging',                 80],
            ]);
        }

        // Hunter Veteran (free for life)
        if (isset($plans['hunter_veteran'])) {
            $id = $plans['hunter_veteran'];
            $rows = array_merge($rows, [
                [$id, 'saved_searches_limit',          'integer', null,  -1,   null,       'Unlimited saved searches',            10],
                [$id, 'lease_applications_per_season', 'integer', null,  -1,   null,       'Unlimited lease applications',        20],
                [$id, 'trail_camera_integration',      'boolean', true,  null, null,       'Trail camera integration',            30],
                [$id, 'digital_id_card',               'boolean', true,  null, null,       'Digital hunter ID card',              40],
                [$id, 'background_checks_per_year',    'integer', null,  1,    null,       '1 background check/year',             50],
                [$id, 'early_listing_access_hours',    'integer', null,  24,   null,       '24-hour early listing access',        60],
                [$id, 'trust_badge_level',             'string',  null,  null, 'veteran',  'Veteran trust badge',                 70],
            ]);
        }

        // Landowner Homestead (free)
        if (isset($plans['landowner_homestead'])) {
            $id = $plans['landowner_homestead'];
            $rows = array_merge($rows, [
                [$id, 'max_active_listings',              'integer', null,  1,   null,       '1 active listing',           10],
                [$id, 'photo_uploads_per_listing',        'integer', null,  10,  null,       'Up to 10 photos/listing',    20],
                [$id, 'video_uploads_per_listing',        'integer', null,  0,   null,       'No video uploads',           30],
                [$id, 'search_placement',                 'string',  null,  null, 'standard', 'Standard search placement', 40],
                [$id, 'advanced_analytics',               'boolean', false, null, null,       'Basic analytics only',      50],
                [$id, 'background_check_credits_per_year','integer', null,  0,   null,       'No background check credits',60],
            ]);
        }

        // Landowner Ranch
        if (isset($plans['landowner_ranch'])) {
            $id = $plans['landowner_ranch'];
            $rows = array_merge($rows, [
                [$id, 'max_active_listings',              'integer', null,  5,   null,       'Up to 5 active listings',           10],
                [$id, 'photo_uploads_per_listing',        'integer', null,  -1,  null,       'Unlimited photos/listing',          20],
                [$id, 'video_uploads_per_listing',        'integer', null,  3,   null,       'Up to 3 videos/listing',            30],
                [$id, 'search_placement',                 'string',  null,  null, 'boosted',  'Boosted search placement',         40],
                [$id, 'advanced_analytics',               'boolean', true,  null, null,       'Advanced analytics dashboard',     50],
                [$id, 'background_check_credits_per_year','integer', null,  5,   null,       '5 background check credits/year',  60],
            ]);
        }

        // Landowner Estate
        if (isset($plans['landowner_estate'])) {
            $id = $plans['landowner_estate'];
            $rows = array_merge($rows, [
                [$id, 'max_active_listings',              'integer', null,  -1,  null,       'Unlimited active listings',          10],
                [$id, 'photo_uploads_per_listing',        'integer', null,  -1,  null,       'Unlimited photos/listing',           20],
                [$id, 'video_uploads_per_listing',        'integer', null,  -1,  null,       'Unlimited video uploads',            30],
                [$id, 'search_placement',                 'string',  null,  null, 'top',      'Top search placement',              40],
                [$id, 'advanced_analytics',               'boolean', true,  null, null,       'Advanced analytics dashboard',      50],
                [$id, 'background_check_credits_per_year','integer', null,  -1,  null,       'Unlimited background check credits', 60],
                [$id, 'dedicated_support',                'boolean', true,  null, null,       'Dedicated support manager',         70],
                [$id, 'api_access',                       'boolean', true,  null, null,       'API access',                        80],
            ]);
        }

        // Club Basic
        if (isset($plans['club_basic'])) {
            $id = $plans['club_basic'];
            $rows = array_merge($rows, [
                [$id, 'shared_calendar',      'boolean', false, null, null,   'Shared hunt calendar',   10],
                [$id, 'stand_assignment',     'boolean', false, null, null,   'Stand assignment tools',  20],
                [$id, 'expense_splitting',    'boolean', false, null, null,   'Expense splitting',       30],
                [$id, 'member_voting',        'boolean', false, null, null,   'Member voting',           40],
                [$id, 'member_announcements', 'boolean', false, null, null,   'Member announcements',    50],
            ]);
        }

        // Club Premium
        if (isset($plans['club_premium'])) {
            $id = $plans['club_premium'];
            $rows = array_merge($rows, [
                [$id, 'shared_calendar',      'boolean', true, null, null,    'Shared hunt calendar',           10],
                [$id, 'stand_assignment',     'boolean', true, null, null,    'Stand assignment tools',          20],
                [$id, 'expense_splitting',    'boolean', true, null, null,    'Expense splitting & reporting',   30],
                [$id, 'member_voting',        'boolean', true, null, null,    'Member voting & polls',           40],
                [$id, 'member_announcements', 'boolean', true, null, null,    'Member announcements',            50],
                [$id, 'shared_trail_cams',    'boolean', true, null, null,    'Shared trail camera access',      60],
                [$id, 'guest_pass_tier',      'string',  null, null, 'full',  'Full guest pass management',      70],
            ]);
        }

        foreach (array_chunk($rows, 50) as $chunk) {
            $platform->table('feature_entitlements')->insert(array_map(fn($r) => [
                'plan_id'      => $r[0],
                'feature_key'  => $r[1],
                'feature_type' => $r[2],
                'bool_value'   => $r[3],
                'int_value'    => $r[4],
                'string_value' => $r[5],
                'display_label'=> $r[6],
                'display_order'=> $r[7],
            ], $chunk));
        }
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS feature_entitlements CASCADE');
    }
};
