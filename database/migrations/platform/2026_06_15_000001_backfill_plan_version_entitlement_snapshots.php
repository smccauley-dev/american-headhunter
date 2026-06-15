<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'platform';

    /**
     * The v1 plan_versions were seeded with an empty entitlements_snapshot ('{}').
     * Subscriptions lock onto a plan_version and EntitlementService resolves a
     * subscriber's features from that version's snapshot (this is the grandfathering
     * mechanism). With empty snapshots, every subscriber would resolve to zero
     * features, so we backfill each version's snapshot from the live
     * feature_entitlements for its plan.
     *
     * Snapshot shape (one entry per feature_key):
     *   { "<feature_key>": { "type": "boolean|integer|string|json", "value": <typed> } }
     *
     * plan_versions carries an immutability RULE (DO INSTEAD NOTHING on UPDATE),
     * so the rule is dropped for the one-time backfill and recreated afterwards.
     * The WHERE guard ensures we only fill versions that are still empty, so a
     * version created later with a real snapshot is never clobbered.
     */
    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            DROP RULE IF EXISTS plan_versions_no_update ON plan_versions;

            UPDATE plan_versions pv
            SET entitlements_snapshot = agg.snapshot
            FROM (
                SELECT
                    fe.plan_id,
                    jsonb_object_agg(
                        fe.feature_key,
                        jsonb_build_object(
                            'type', fe.feature_type,
                            'value', CASE fe.feature_type
                                WHEN 'boolean' THEN to_jsonb(fe.bool_value)
                                WHEN 'integer' THEN to_jsonb(fe.int_value)
                                WHEN 'string'  THEN to_jsonb(fe.string_value)
                                WHEN 'json'    THEN fe.json_value
                                ELSE 'null'::jsonb
                            END
                        )
                    ) AS snapshot
                FROM feature_entitlements fe
                GROUP BY fe.plan_id
            ) agg
            WHERE pv.plan_id = agg.plan_id
              AND pv.entitlements_snapshot = '{}'::jsonb;

            CREATE RULE plan_versions_no_update AS ON UPDATE TO plan_versions DO INSTEAD NOTHING;
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            DROP RULE IF EXISTS plan_versions_no_update ON plan_versions;

            UPDATE plan_versions SET entitlements_snapshot = '{}'::jsonb;

            CREATE RULE plan_versions_no_update AS ON UPDATE TO plan_versions DO INSTEAD NOTHING;
        SQL);
    }
};
