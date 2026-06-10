<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE membership_plans (
                id                      UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                plan_key                VARCHAR(50) NOT NULL,
                account_type            VARCHAR(30) NOT NULL,
                display_name            VARCHAR(100) NOT NULL,
                description             TEXT,
                tagline                 VARCHAR(255),

                monthly_price_cents     INTEGER NOT NULL DEFAULT 0,
                annual_price_cents      INTEGER NOT NULL DEFAULT 0,
                currency                CHAR(3) NOT NULL DEFAULT 'USD',

                platform_fee_pct        DECIMAL(5,2),
                commission_pct          DECIMAL(5,2),

                monthly_enabled         BOOLEAN NOT NULL DEFAULT true,
                annual_enabled          BOOLEAN NOT NULL DEFAULT true,

                stripe_product_id       VARCHAR(100),
                stripe_monthly_price_id VARCHAR(100),
                stripe_annual_price_id  VARCHAR(100),

                sort_order              INTEGER NOT NULL DEFAULT 0,
                is_public               BOOLEAN NOT NULL DEFAULT true,
                is_active               BOOLEAN NOT NULL DEFAULT true,
                is_default_free         BOOLEAN NOT NULL DEFAULT false,

                admin_notes             TEXT,
                launched_at             TIMESTAMPTZ,
                deprecated_at           TIMESTAMPTZ,

                created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                deleted_at              TIMESTAMPTZ,

                CONSTRAINT uq_membership_plans_key UNIQUE (plan_key)
            );

            CREATE INDEX idx_membership_plans_account_type ON membership_plans (account_type)
                WHERE deleted_at IS NULL;
            CREATE INDEX idx_membership_plans_active ON membership_plans (is_active, is_public)
                WHERE deleted_at IS NULL;
            CREATE INDEX idx_membership_plans_sort ON membership_plans (account_type, sort_order)
                WHERE deleted_at IS NULL;

            CREATE TRIGGER set_updated_at BEFORE UPDATE ON membership_plans
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();

            -- Hunter plans
            INSERT INTO membership_plans (plan_key, account_type, display_name, tagline, monthly_price_cents, annual_price_cents, sort_order, is_default_free, launched_at) VALUES
                ('hunter_scout',     'hunter',    'Scout',     'Free forever',                   0,     0,      10, true,  NOW()),
                ('hunter_sportsman', 'hunter',    'Sportsman', 'For the serious hunter',         999,   9990,   20, false, NOW()),
                ('hunter_outfitter', 'hunter',    'Outfitter', 'Premium access, maximum trust',  2499,  24990,  30, false, NOW()),
                ('hunter_veteran',   'hunter',    'Veteran',   'In honor of your service',       0,     0,      40, false, NOW());

            -- Landowner plans
            INSERT INTO membership_plans (plan_key, account_type, display_name, tagline, monthly_price_cents, annual_price_cents, platform_fee_pct, sort_order, is_default_free, launched_at) VALUES
                ('landowner_homestead', 'landowner', 'Homestead', 'Start listing for free',         0,     0,      5.00, 10, true,  NOW()),
                ('landowner_ranch',     'landowner', 'Ranch',     'Grow your leasing income',       2999,  29990,  3.00, 20, false, NOW()),
                ('landowner_estate',    'landowner', 'Estate',    'Maximum exposure, minimum fees', 9999,  99990,  0.00, 30, false, NOW());

            -- Club plans
            INSERT INTO membership_plans (plan_key, account_type, display_name, tagline, monthly_price_cents, annual_price_cents, sort_order, is_default_free, launched_at) VALUES
                ('club_basic',   'club', 'Club Basic',   'Core club management tools',   0,    0,     10, true,  NOW()),
                ('club_premium', 'club', 'Club Premium', 'Everything your club needs',   1499, 14990, 20, false, NOW());

            -- Outfitter plan
            INSERT INTO membership_plans (plan_key, account_type, display_name, tagline, monthly_price_cents, annual_price_cents, commission_pct, sort_order, is_default_free, launched_at) VALUES
                ('outfitter_standard', 'outfitter', 'Outfitter Standard', 'List packages, grow bookings', 4900, 49000, 10.00, 10, false, NOW());

            -- Consultant plan
            INSERT INTO membership_plans (plan_key, account_type, display_name, tagline, monthly_price_cents, annual_price_cents, commission_pct, sort_order, is_default_free, launched_at) VALUES
                ('consultant_basic', 'consultant', 'Consultant', 'List your services, we handle billing', 0, 0, 15.00, 10, true, NOW());

            -- Marketplace Seller plan
            INSERT INTO membership_plans (plan_key, account_type, display_name, tagline, monthly_price_cents, annual_price_cents, commission_pct, sort_order, is_default_free, launched_at) VALUES
                ('seller_standard', 'seller', 'Marketplace Seller', 'Sell gear to fellow hunters', 0, 0, 8.00, 10, true, NOW());
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS membership_plans CASCADE');
    }
};
