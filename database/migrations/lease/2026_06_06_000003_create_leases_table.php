<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'lease';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE leases (
                id                 UUID          NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                application_id     UUID          NOT NULL REFERENCES lease_applications (id),
                property_id        UUID          NOT NULL,  -- References DB 2 (Property) properties.id
                listing_id         UUID          NOT NULL,  -- References DB 2 (Property) property_listings.id
                lessee_user_id     UUID          NOT NULL,  -- References DB 1 (Identity) users.id
                lessor_user_id     UUID          NOT NULL,  -- References DB 1 (Identity) users.id (property owner)
                status             VARCHAR(25)   NOT NULL DEFAULT 'pending_signatures'
                                       CHECK (status IN ('pending_signatures', 'active', 'expired', 'terminated', 'cancelled')),
                start_date         DATE          NOT NULL,
                end_date           DATE          NOT NULL,
                total_price        NUMERIC(10,2) NOT NULL,
                deposit_paid       NUMERIC(10,2) NOT NULL DEFAULT 0.00,
                auto_renew         BOOLEAN       NOT NULL DEFAULT false,
                terminated_at      TIMESTAMPTZ   NULL,
                termination_reason TEXT          NULL,
                created_at         TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
                updated_at         TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
                deleted_at         TIMESTAMPTZ   NULL,

                CONSTRAINT chk_leases_dates CHECK (end_date > start_date)
            );

            CREATE UNIQUE INDEX uq_leases_application_id  ON leases (application_id);
            CREATE        INDEX idx_leases_property_id    ON leases (property_id);
            CREATE        INDEX idx_leases_listing_id     ON leases (listing_id);
            CREATE        INDEX idx_leases_lessee_user_id ON leases (lessee_user_id);
            CREATE        INDEX idx_leases_lessor_user_id ON leases (lessor_user_id);
            CREATE        INDEX idx_leases_status         ON leases (status);
            CREATE        INDEX idx_leases_dates          ON leases (start_date, end_date);
            CREATE        INDEX idx_leases_deleted_at     ON leases (deleted_at) WHERE deleted_at IS NOT NULL;

            CREATE TRIGGER trg_leases_updated_at
                BEFORE UPDATE ON leases
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS leases CASCADE;');
    }
};
