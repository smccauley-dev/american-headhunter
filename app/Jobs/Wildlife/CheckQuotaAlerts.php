<?php

namespace App\Jobs\Wildlife;

use App\Models\Wildlife\HarvestQuota;
use App\Services\Communications\NotificationService;
use App\Services\Property\PropertyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Daily sweep that warns a landowner when a species' harvest quota is running
 * out. A quota crossing 75% (then 90%) of its tags notifies the property owner
 * once per band — `alert_threshold_notified` records the highest band already
 * sent so a subsequent daily run never re-nags at the same level. The landowner
 * is resolved cross-DB through PropertyService (property_id → DB 2 owner); the
 * notification is system-authored via NotificationService (ah_system).
 */
class CheckQuotaAlerts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(PropertyService $properties, NotificationService $notifications): void
    {
        // Only quotas at or past the first (75%) band that have not yet been
        // notified at the top (90%) band are candidates — the rest can't change
        // a landowner's alert state on this run.
        $quotas = HarvestQuota::on('wildlife')
            ->where('max_harvest', '>', 0)
            ->whereRaw('current_harvest >= max_harvest * 0.75')
            ->where('alert_threshold_notified', '<', 90)
            ->get();

        foreach ($quotas as $quota) {
            $ratio = $quota->current_harvest / $quota->max_harvest;
            $band = $ratio >= 0.90 ? 90 : ($ratio >= 0.75 ? 75 : 0);

            if ($band <= $quota->alert_threshold_notified) {
                continue;
            }

            $ownerId = $properties->find($quota->property_id)?->owner_user_id;

            if ($ownerId === null) {
                continue; // orphaned quota — retry on a future run once resolvable
            }

            $notifications->notify(
                userId: $ownerId,
                type: 'wildlife.quota_threshold',
                title: "Harvest quota {$band}% reached",
                body: "{$quota->species_code} is at {$quota->current_harvest} of {$quota->max_harvest} tags for the {$quota->season_year} season on one of your properties.",
                data: [
                    'property_id' => $quota->property_id,
                    'lease_id' => $quota->lease_id,
                    'species_code' => $quota->species_code,
                    'season_year' => $quota->season_year,
                    'threshold' => $band,
                ],
            );

            $quota->alert_threshold_notified = $band;
            $quota->save();
        }
    }
}
