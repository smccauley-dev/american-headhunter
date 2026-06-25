<?php

namespace App\Models\Analytics;

use App\Models\ReadOnlyModel;

/**
 * Sensitive revenue rollup (DB 8). Read ONLY via the `analytics_admin`
 * (ah_system) connection — ah_readonly has no SELECT grant on this table, so the
 * public/runtime read path cannot reach it. Populated alongside PlatformSnapshot
 * by App\Jobs\Etl\SyncPlatformSnapshot, keyed by a shared captured_at.
 */
class RevenueSnapshot extends ReadOnlyModel
{
    protected $connection = 'analytics_admin';

    protected $table = 'revenue_snapshots';

    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
            'created_at'  => 'datetime',
        ];
    }
}
