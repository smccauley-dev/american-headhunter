<?php

namespace App\Jobs\Etl;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Hourly ETL that recomputes the platform analytics rollups in DB 8.
 *
 * Each metric is queried against its OWN connection (no cross-DB joins) and
 * assembled in PHP, then written to DB 8 via the `analytics_etl` (ah_etl, owner)
 * connection — the only place the app writes analytics. Public-safe counts go to
 * platform_snapshots; sensitive revenue goes to revenue_snapshots; both share one
 * captured_at so a snapshot pair lines up.
 *
 * Runs on the queue as ah_system (BYPASSRLS), so aggregates over RLS-protected
 * billing tables see every row. Only aggregate cent-sums are computed — never any
 * payment-instrument data, so nothing sensitive is logged.
 */
class SyncPlatformSnapshot implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $capturedAt = now();
        $since30d   = $capturedAt->copy()->subDays(30);

        // ── DB 1: Identity ───────────────────────────────────────────────────
        $users = DB::connection('identity')->table('users')->whereNull('deleted_at');

        $usersByType = DB::connection('identity')->table('users')
            ->whereNull('deleted_at')
            ->selectRaw('account_type, COUNT(*) AS c')
            ->groupBy('account_type')
            ->pluck('c', 'account_type')
            ->map(fn ($c) => (int) $c)
            ->toArray();

        $totalUsers  = (clone $users)->count();
        $activeUsers = (clone $users)->where('status', 'active')
            ->where('last_login_at', '>=', $since30d)
            ->count();
        $newUsers30d = (clone $users)->where('created_at', '>=', $since30d)->count();

        // ── DB 2: Property ───────────────────────────────────────────────────
        $totalProperties = DB::connection('property')->table('properties')
            ->whereNull('deleted_at')->count();
        $totalListings = DB::connection('property')->table('property_listings')
            ->whereNull('deleted_at')->count();
        $activeListings = DB::connection('property')->table('property_listings')
            ->whereNull('deleted_at')->where('status', 'active')->count();

        $acres = DB::connection('property')->table('properties')
            ->whereNull('deleted_at')
            ->selectRaw('COALESCE(SUM(total_acres), 0) AS total, COALESCE(SUM(huntable_acres), 0) AS huntable')
            ->first();

        // ── DB 3: Lease ──────────────────────────────────────────────────────
        $leasesByStatus = DB::connection('lease')->table('leases')
            ->whereNull('deleted_at')
            ->selectRaw('status, COUNT(*) AS c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->map(fn ($c) => (int) $c)
            ->toArray();

        $totalLeases  = array_sum($leasesByStatus);
        $activeLeases = $leasesByStatus['active'] ?? 0;

        // ── DB 4: Billing (sensitive) ────────────────────────────────────────
        $gmvCents = (int) DB::connection('billing')->table('payments')
            ->where('status', 'succeeded')->sum('amount_cents');
        $feesCents = (int) DB::connection('billing')->table('invoices')
            ->where('status', 'paid')->sum('platform_fee_cents');
        $payoutsCents = (int) DB::connection('billing')->table('payouts')
            ->where('status', 'paid')->sum('amount_cents');

        // ── Write DB 8 (ETL owner connection only) ───────────────────────────
        DB::connection('analytics_etl')->table('platform_snapshots')->insert([
            'id'               => (string) Str::uuid(),
            'captured_at'      => $capturedAt,
            'total_users'      => $totalUsers,
            'active_users'     => $activeUsers,
            'new_users_30d'    => $newUsers30d,
            'users_by_type'    => json_encode($usersByType),
            'total_properties' => $totalProperties,
            'total_listings'   => $totalListings,
            'active_listings'  => $activeListings,
            'total_leases'     => $totalLeases,
            'active_leases'    => $activeLeases,
            'leases_by_status' => json_encode($leasesByStatus),
            'total_acres'      => $acres->total ?? 0,
            'huntable_acres'   => $acres->huntable ?? 0,
        ]);

        DB::connection('analytics_etl')->table('revenue_snapshots')->insert([
            'id'                  => (string) Str::uuid(),
            'captured_at'         => $capturedAt,
            'gmv_cents'           => $gmvCents,
            'platform_fees_cents' => $feesCents,
            'payouts_cents'       => $payoutsCents,
        ]);
    }
}
