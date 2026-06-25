<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Veteran tier is a $0 grant-target plan (granted on verification, never
 * bought), but it seeded with is_public = true. On the public pricing page a
 * $0 plan that isn't is_default_free renders as "Contact", which is misleading
 * — the benefit is earned by verifying service, not by contacting sales. Hide
 * it from the public cards; eligibility still flows through the veteran
 * promotion + EntitlementService (which resolve by plan id regardless of
 * is_public), and the pricing page gains a dedicated "verify your service"
 * callout instead.
 *
 * Idempotent and ordering-safe: later-stamped than the seed migration, so it
 * flips the live row on both fresh and existing databases.
 */
return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        DB::connection($this->connection)
            ->table('membership_plans')
            ->where('plan_key', 'hunter_veteran')
            ->update(['is_public' => false]);
    }

    public function down(): void
    {
        DB::connection($this->connection)
            ->table('membership_plans')
            ->where('plan_key', 'hunter_veteran')
            ->update(['is_public' => true]);
    }
};
