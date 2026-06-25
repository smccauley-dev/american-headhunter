<?php

namespace App\Models\Analytics;

use App\Models\ReadOnlyModel;

/**
 * Public-safe platform rollup (DB 8). Read via the `analytics` (ah_readonly)
 * connection. Populated by App\Jobs\Etl\SyncPlatformSnapshot. Holds NO revenue —
 * that lives in RevenueSnapshot behind a restricted grant.
 */
class PlatformSnapshot extends ReadOnlyModel
{
    protected $connection = 'analytics';

    protected $table = 'platform_snapshots';

    protected function casts(): array
    {
        return [
            'captured_at'      => 'datetime',
            'created_at'       => 'datetime',
            'users_by_type'    => 'array',
            'leases_by_status' => 'array',
            'total_acres'      => 'decimal:2',
            'huntable_acres'   => 'decimal:2',
        ];
    }
}
