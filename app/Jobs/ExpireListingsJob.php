<?php

namespace App\Jobs;

use App\Models\Property\PropertyListing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExpireListingsJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        PropertyListing::on('property')
            ->where('status', 'active')
            ->whereNotNull('season_end')
            ->whereDate('season_end', '<', now()->toDateString())
            ->whereNull('deleted_at')
            ->update([
                'status'     => 'expired',
                'updated_at' => now(),
            ]);
    }
}
