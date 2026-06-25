<?php

namespace App\Services\Analytics;

use App\Models\Analytics\PlatformSnapshot;
use App\Models\Analytics\RevenueSnapshot;

/**
 * Read-only access to the DB 8 analytics rollups.
 *
 * Counts/acres come from platform_snapshots via the `analytics` (ah_readonly)
 * connection and are safe for any surface, including the public homepage.
 * Revenue comes from revenue_snapshots via `analytics_admin` (ah_system) — only
 * the admin dashboard calls revenue(); publicStats() never touches it, and the
 * read role for public/runtime can't read that table anyway.
 */
class AnalyticsService
{
    /** Latest public-safe snapshot, or null before the first ETL run. */
    public function current(): ?PlatformSnapshot
    {
        return PlatformSnapshot::query()->orderByDesc('captured_at')->first();
    }

    /** Latest revenue snapshot (admin-only path). */
    public function revenue(): ?RevenueSnapshot
    {
        return RevenueSnapshot::query()->orderByDesc('captured_at')->first();
    }

    /**
     * Minimal, public-safe stats for the marketing homepage. Counts only — never
     * revenue. Returns zeros before the first ETL run so the page still renders.
     */
    public function publicStats(): array
    {
        $s = $this->current();

        return [
            'total_users'  => (int) ($s->total_users ?? 0),
            'total_leases' => (int) ($s->total_leases ?? 0),
            'total_acres'  => (float) ($s->total_acres ?? 0),
        ];
    }

    /**
     * Full count/acre payload for the admin dashboard tabs (no revenue). The
     * caller pulls revenue() separately for the Revenue tab.
     */
    public function dashboardCounts(): array
    {
        $s = $this->current();

        return [
            'captured_at'      => $s?->captured_at,
            'total_users'      => (int) ($s->total_users ?? 0),
            'active_users'     => (int) ($s->active_users ?? 0),
            'new_users_30d'    => (int) ($s->new_users_30d ?? 0),
            'users_by_type'    => (array) ($s->users_by_type ?? []),
            'total_properties' => (int) ($s->total_properties ?? 0),
            'total_listings'   => (int) ($s->total_listings ?? 0),
            'active_listings'  => (int) ($s->active_listings ?? 0),
            'total_leases'     => (int) ($s->total_leases ?? 0),
            'active_leases'    => (int) ($s->active_leases ?? 0),
            'leases_by_status' => (array) ($s->leases_by_status ?? []),
            'total_acres'      => (float) ($s->total_acres ?? 0),
            'huntable_acres'   => (float) ($s->huntable_acres ?? 0),
        ];
    }
}
