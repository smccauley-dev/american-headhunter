<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE feature_flags (
                id                      UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                key                     VARCHAR(100) NOT NULL,
                display_name            VARCHAR(200) NOT NULL,
                description             TEXT,
                is_enabled              BOOLEAN NOT NULL DEFAULT false,
                enabled_for_roles       JSONB NOT NULL DEFAULT '[]',
                enabled_for_user_ids    JSONB NOT NULL DEFAULT '[]',
                rollout_percentage      SMALLINT NOT NULL DEFAULT 0,
                created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),

                CONSTRAINT uq_feature_flags_key UNIQUE (key),
                CONSTRAINT chk_feature_flags_rollout
                    CHECK (rollout_percentage >= 0 AND rollout_percentage <= 100)
            );

            CREATE INDEX idx_feature_flags_key ON feature_flags (key);
            CREATE INDEX idx_feature_flags_enabled ON feature_flags (is_enabled);

            CREATE TRIGGER set_updated_at BEFORE UPDATE ON feature_flags
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();

            INSERT INTO feature_flags (key, display_name, is_enabled) VALUES
                ('auction_module',               'Live Lease Auctions',                  false),
                ('consulting_marketplace',       'Wildlife Consulting Marketplace',       false),
                ('outfitter_booking',            'Outfitter & Guide Bookings',            false),
                ('equipment_marketplace',        'Equipment & Gear Marketplace',          false),
                ('club_leases',                  'Hunting Club Lease Support',            true),
                ('carbon_credits',               'Carbon Credit Leasing',                 false),
                ('smart_lock_iot',               'Smart Lock & IoT Integrations',         false),
                ('bundled_insurance',            'Bundled Lease Insurance',               false),
                ('ai_trophy_scoring',            'AI Trophy Score Estimation',            false),
                ('public_api',                   'Public Developer API',                  false),
                ('data_monetization',            'Research Data Licensing',               false),
                ('digital_id_cards',             'Digital Hunter ID Cards',               false),
                ('veteran_discounts',            'Veteran Discount Program',              true),
                ('youth_programs',               'Youth Hunter Programs',                 true),
                ('offline_pwa',                  'Offline Progressive Web App',           false),
                ('saml_sso',                     'Enterprise SAML SSO',                   false),
                ('two_person_authorization',     'Dual-Approval Admin Actions',           false),
                ('lease_wanted_board',           'Lease Wanted Board',                    false),
                ('population_modeling',          'Wildlife Population Modeling',          false),
                ('wildlife_photography_tourism', 'Wildlife Photography Tourism',          false),
                ('club_expense_sharing',         'Club Expense Sharing & Splitting',      false);
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS feature_flags CASCADE');
    }
};
