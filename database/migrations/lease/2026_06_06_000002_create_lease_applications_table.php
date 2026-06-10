<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'lease';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE lease_applications (
                id                  UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                listing_id          UUID        NOT NULL,  -- References DB 2 (Property) property_listings.id
                applicant_user_id   UUID        NOT NULL,  -- References DB 1 (Identity) users.id
                application_type    VARCHAR(20) NOT NULL DEFAULT 'individual'
                                        CHECK (application_type IN ('individual', 'club')),
                status              VARCHAR(20) NOT NULL DEFAULT 'pending'
                                        CHECK (status IN ('pending', 'under_review', 'approved', 'rejected', 'withdrawn', 'expired')),
                message             TEXT        NULL,
                desired_hunters     SMALLINT    NULL,
                proposed_start      DATE        NULL,
                proposed_end        DATE        NULL,
                reviewed_by_user_id UUID        NULL,  -- References DB 1 (Identity) users.id
                reviewed_at         TIMESTAMPTZ NULL,
                rejection_reason    TEXT        NULL,
                created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                deleted_at          TIMESTAMPTZ NULL
            );

            CREATE INDEX idx_lease_applications_listing_id        ON lease_applications (listing_id);
            CREATE INDEX idx_lease_applications_applicant_user_id ON lease_applications (applicant_user_id);
            CREATE INDEX idx_lease_applications_status            ON lease_applications (status);
            CREATE INDEX idx_lease_applications_deleted_at        ON lease_applications (deleted_at)
                WHERE deleted_at IS NOT NULL;

            CREATE TRIGGER trg_lease_applications_updated_at
                BEFORE UPDATE ON lease_applications
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS lease_applications CASCADE;');
    }
};
