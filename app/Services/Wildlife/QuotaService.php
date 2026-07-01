<?php

namespace App\Services\Wildlife;

use App\Models\Wildlife\HarvestQuota;
use App\Services\BaseService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Harvest quota enforcement.
 *
 * A quota is keyed on (property, lease-or-property-wide, species, season year).
 * A lease-specific row takes precedence over the property-wide row for the same
 * species/year. When no row exists the species is unquotaed (unlimited).
 *
 * Consumption is a single atomic UPDATE guarded by the row's own count, so it is
 * safe under concurrency and under offline replay: two requests racing the last
 * tag can never both win, and the DB CHECK (current_harvest <= max_harvest) is a
 * second backstop.
 */
class QuotaService extends BaseService
{
    /**
     * The quota row that governs a harvest: the lease-specific row if one exists,
     * otherwise the property-wide row, otherwise null (species is unquotaed).
     */
    public function applicable(string $propertyId, ?string $leaseId, string $speciesCode, int $seasonYear): ?HarvestQuota
    {
        if ($leaseId !== null) {
            $leaseQuota = HarvestQuota::on('wildlife')
                ->where('property_id', $propertyId)
                ->where('lease_id', $leaseId)
                ->where('species_code', $speciesCode)
                ->where('season_year', $seasonYear)
                ->first();

            if ($leaseQuota) {
                return $leaseQuota;
            }
        }

        return HarvestQuota::on('wildlife')
            ->where('property_id', $propertyId)
            ->whereNull('lease_id')
            ->where('species_code', $speciesCode)
            ->where('season_year', $seasonYear)
            ->first();
    }

    /**
     * Tags left for a species. Null means unquotaed (no limit configured).
     */
    public function remaining(string $propertyId, ?string $leaseId, string $speciesCode, int $seasonYear): ?int
    {
        return $this->applicable($propertyId, $leaseId, $speciesCode, $seasonYear)?->remaining();
    }

    /**
     * Atomically claim one tag. Returns true when consumed (or when the species is
     * unquotaed); false when the quota is already full. Callers reject a false with
     * a 409 conflict.
     */
    public function tryConsume(string $propertyId, ?string $leaseId, string $speciesCode, int $seasonYear): bool
    {
        $quota = $this->applicable($propertyId, $leaseId, $speciesCode, $seasonYear);

        if ($quota === null) {
            return true; // unquotaed species — always allowed
        }

        $affected = DB::connection('wildlife')->update(
            'UPDATE harvest_quotas SET current_harvest = current_harvest + 1
             WHERE id = ? AND current_harvest < max_harvest',
            [$quota->id]
        );

        return $affected === 1;
    }

    /**
     * Return a claimed tag — used when a harvest that consumed a tag is removed.
     * Floors at zero so a double-release can never underflow the count.
     */
    public function release(string $propertyId, ?string $leaseId, string $speciesCode, int $seasonYear): void
    {
        $quota = $this->applicable($propertyId, $leaseId, $speciesCode, $seasonYear);

        if ($quota === null) {
            return;
        }

        DB::connection('wildlife')->update(
            'UPDATE harvest_quotas SET current_harvest = GREATEST(current_harvest - 1, 0)
             WHERE id = ?',
            [$quota->id]
        );
    }

    /**
     * Every quota governing a lease, one row per species: the lease-specific row
     * wins over the property-wide row for the same species. For the quota view /
     * offline cache.
     *
     * @return Collection<int,HarvestQuota>
     */
    public function listForLease(string $propertyId, ?string $leaseId, int $seasonYear): Collection
    {
        $rows = HarvestQuota::on('wildlife')
            ->where('property_id', $propertyId)
            ->where('season_year', $seasonYear)
            ->where(function ($q) use ($leaseId) {
                $q->whereNull('lease_id');
                if ($leaseId !== null) {
                    $q->orWhere('lease_id', $leaseId);
                }
            })
            ->get();

        // Prefer the lease-specific row per species.
        return $rows
            ->sortByDesc(fn (HarvestQuota $q) => $q->lease_id !== null ? 1 : 0)
            ->unique('species_code')
            ->values();
    }
}
