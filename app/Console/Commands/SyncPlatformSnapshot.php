<?php

namespace App\Console\Commands;

use App\Jobs\Etl\SyncPlatformSnapshot as SyncPlatformSnapshotJob;
use Illuminate\Console\Command;

/**
 * Recompute the platform analytics snapshot now (the same job the hourly
 * scheduler and the dashboard "Refresh now" button run). Runs synchronously so
 * the row exists by the time the command returns.
 *
 *   php artisan analytics:sync-platform
 */
class SyncPlatformSnapshot extends Command
{
    protected $signature   = 'analytics:sync-platform';
    protected $description = 'Recompute the platform analytics snapshot (DB 8) now';

    public function handle(): int
    {
        (new SyncPlatformSnapshotJob)->handle();

        $this->info('Platform analytics snapshot recomputed.');

        return self::SUCCESS;
    }
}
