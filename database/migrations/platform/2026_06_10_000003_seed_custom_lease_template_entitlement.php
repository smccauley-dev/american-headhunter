<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        $platform = DB::connection($this->connection);

        $plans = $platform->table('membership_plans')
            ->pluck('id', 'plan_key');

        $rows = [];

        foreach (['landowner_ranch', 'landowner_estate'] as $planKey) {
            if (isset($plans[$planKey])) {
                $rows[] = [
                    'plan_id'       => $plans[$planKey],
                    'feature_key'   => 'custom_lease_template',
                    'feature_type'  => 'boolean',
                    'bool_value'    => true,
                    'int_value'     => null,
                    'string_value'  => null,
                    'display_label' => 'Custom Lease Template Upload',
                    'display_order' => 90,
                ];
            }
        }

        if (! empty($rows)) {
            $platform->table('feature_entitlements')->insert($rows);
        }
    }

    public function down(): void
    {
        DB::connection($this->connection)
            ->table('feature_entitlements')
            ->where('feature_key', 'custom_lease_template')
            ->delete();
    }
};
