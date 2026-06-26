<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5.x — allow the 'deposit_forfeited_against_user' Trust Score event.
 *
 * Recorded against a hunter when an admin CONFIRMS a hunter-fault security-deposit
 * forfeiture (the penalty is provisional until then). Extends the existing
 * trust_score_events.event_type CHECK constraint to include it.
 */
return new class extends Migration
{
    protected $connection = 'identity';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE trust_score_events DROP CONSTRAINT trust_score_events_event_type_check;
            ALTER TABLE trust_score_events ADD CONSTRAINT trust_score_events_event_type_check
                CHECK (event_type IN (
                    'background_check_passed',
                    'background_check_failed',
                    'lease_completed',
                    'lease_terminated_early',
                    'dispute_raised',
                    'dispute_resolved_for_user',
                    'dispute_resolved_against_user',
                    'deposit_forfeited_against_user',
                    'verified_landowner',
                    'email_verified',
                    'phone_verified',
                    'id_verified',
                    'ofac_cleared',
                    'ofac_match',
                    'positive_review',
                    'negative_review',
                    'account_suspended',
                    'admin_adjustment'
                ));
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE trust_score_events DROP CONSTRAINT trust_score_events_event_type_check;
            ALTER TABLE trust_score_events ADD CONSTRAINT trust_score_events_event_type_check
                CHECK (event_type IN (
                    'background_check_passed',
                    'background_check_failed',
                    'lease_completed',
                    'lease_terminated_early',
                    'dispute_raised',
                    'dispute_resolved_for_user',
                    'dispute_resolved_against_user',
                    'verified_landowner',
                    'email_verified',
                    'phone_verified',
                    'id_verified',
                    'ofac_cleared',
                    'ofac_match',
                    'positive_review',
                    'negative_review',
                    'account_suspended',
                    'admin_adjustment'
                ));
        SQL);
    }
};
