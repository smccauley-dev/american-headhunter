<?php

namespace App\Database;

use Illuminate\Support\Facades\DB;

/**
 * SEC-043 — runtime database role selection.
 *
 * The application owns its tables as `ah_app`, which bypasses RLS as the table
 * owner. To make RLS actually enforce, user-facing requests connect as the
 * non-owner `ah_runtime` (the config default). Two other roles exist for paths
 * that legitimately cannot run under per-user RLS:
 *
 *   - ah_app    (OWNER)  — migrations / seeders / schema. DDL. Bypasses RLS as
 *                          owner. NEVER a user-facing runtime connection.
 *   - ah_runtime(RUNTIME)— user-facing HTTP. Non-owner; RLS applies. DML only.
 *   - ah_system (SYSTEM) — auth bootstrap, queue worker, Filament admin. A
 *                          non-owner (cannot DDL) with BYPASSRLS for trusted /
 *                          pre-context work. Inherits ah_runtime's DML grants
 *                          via role membership.
 *
 * This helper swaps the username/password of every app *writer* connection at
 * runtime. Read replicas (ah_readonly) and the analytics/research ETL
 * connections (ah_etl) are intentionally untouched. Under PHP-FPM the
 * application is rebuilt per request, so a mid-request swap never leaks into the
 * next request.
 */
final class ConnectionRole
{
    /**
     * The app writer connections. Read replicas (*_read), analytics, analytics_etl
     * and research are deliberately excluded — they use their own dedicated roles.
     */
    public const APP_CONNECTIONS = [
        'identity', 'property', 'lease', 'billing', 'wildlife', 'commerce',
        'communications', 'audit', 'incidents', 'documents', 'platform', 'geospatial',
    ];

    public static function useOwner(bool $purge = false): void
    {
        self::apply(env('DB_APP_USERNAME', 'ah_app'), env('DB_APP_PASSWORD', 'secret'), $purge);
    }

    public static function useSystem(bool $purge = false): void
    {
        self::apply(env('DB_SYSTEM_USERNAME', 'ah_system'), env('DB_SYSTEM_PASSWORD', 'secret'), $purge);
    }

    /**
     * Run a closure with every app writer connection elevated to ah_system
     * (BYPASSRLS), then restore the exact credentials that were in effect before.
     *
     * Use for trusted state transitions that legitimately write RLS-protected
     * tables from a user-facing (ah_runtime) request — e.g. activating a lease on
     * the final e-signature. The `leases` table only grants SELECT under
     * ah_runtime, so an UPDATE there silently affects zero rows; this runs the
     * write under a role that can persist it without broadening party write access.
     *
     * Capturing and restoring the prior creds (rather than assuming ah_runtime)
     * keeps this safe to nest and correct under console/queue (ah_system) too.
     */
    public static function asSystem(callable $callback): mixed
    {
        $previous = [];
        foreach (self::APP_CONNECTIONS as $connection) {
            $previous[$connection] = [
                config("database.connections.{$connection}.username"),
                config("database.connections.{$connection}.password"),
            ];
        }

        self::useSystem(true);

        try {
            return $callback();
        } finally {
            foreach (self::APP_CONNECTIONS as $connection) {
                [$username, $password] = $previous[$connection];
                config([
                    "database.connections.{$connection}.username" => $username,
                    "database.connections.{$connection}.password" => $password,
                ]);
                DB::purge($connection);
            }
        }
    }

    private static function apply(string $username, string $password, bool $purge): void
    {
        foreach (self::APP_CONNECTIONS as $connection) {
            config([
                "database.connections.{$connection}.username" => $username,
                "database.connections.{$connection}.password" => $password,
            ]);

            // Mid-request swaps must drop any already-opened connection so the
            // next query reconnects with the new credentials.
            if ($purge) {
                DB::purge($connection);
            }
        }
    }
}
