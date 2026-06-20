<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Outfitter" is its own account type/membership; it must not also name a hunter
 * tier. Rename the premium hunter tier (plan_key hunter_outfitter, display_name
 * "Outfitter") to "Pro" / hunter_pro.
 *
 * Idempotent and ordering-safe: on a fresh install the seed migration creates
 * hunter_outfitter first, then this later-stamped migration renames it; on an
 * existing database it renames the live row. The plan id is unchanged, so its
 * feature_entitlements and plan_versions stay linked.
 */
return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        DB::connection($this->connection)
            ->table('membership_plans')
            ->where('plan_key', 'hunter_outfitter')
            ->update([
                'plan_key'     => 'hunter_pro',
                'display_name' => 'Pro',
            ]);
    }

    public function down(): void
    {
        DB::connection($this->connection)
            ->table('membership_plans')
            ->where('plan_key', 'hunter_pro')
            ->update([
                'plan_key'     => 'hunter_outfitter',
                'display_name' => 'Outfitter',
            ]);
    }
};
