<?php

namespace App\Database;

use Illuminate\Database\Connection;

/**
 * SEC-043 — request-scoped row-level-security context.
 *
 * Holds the current user id + role and applies them to a database connection as
 * PostgreSQL session variables (app.current_user_id / app.user_role) that RLS
 * policies read. Bound as a singleton; under PHP-FPM the application is rebuilt
 * per request, so each request gets a fresh, isolated context.
 *
 * Injection is LAZY (SEC-056). InjectDatabaseContext records the user here and a
 * ConnectionEstablished listener (DatabaseServiceProvider) applies the context the
 * first time each connection is actually opened. The previous middleware opened a
 * PDO for all ~14 databases on every request to set the variables eagerly — under
 * load that exhausted PostgreSQL's connection slots, and the connection that lost
 * the race had its context silently skipped, making every RLS-protected read on
 * it return zero rows. Opening only the databases a request truly uses removes the
 * pressure; re-applying on (re)connect also keeps context correct after a
 * DB::purge (e.g. ConnectionRole role swaps).
 */
final class RlsContext
{
    /**
     * Connections that carry user-scoped RLS reachable from an HTTP request.
     *
     * SEC-023/D02 — audit (DB 9), analytics/analytics_etl (DB 8) and research
     * (DB 14) are intentionally excluded: they have no user-scoped RLS reachable
     * from the HTTP layer. If such a policy is ever added there, add it here too.
     */
    public const CONNECTIONS = [
        'identity', 'property', 'property_read',
        'lease', 'billing', 'wildlife', 'wildlife_read',
        'commerce', 'communications',
        'incidents', 'documents', 'platform',
        'geospatial', 'geospatial_read',
    ];

    private bool $ready = false;

    private string $userId = '';

    private string $userRole = '';

    /** Record the resolved user for this request and arm lazy injection. */
    public function set(string $userId, string $userRole): void
    {
        $this->userId   = $userId;
        $this->userRole = $userRole;
        $this->ready    = true;
    }

    public function isReady(): bool
    {
        return $this->ready;
    }

    public function appliesTo(string $connectionName): bool
    {
        return in_array($connectionName, self::CONNECTIONS, true);
    }

    /**
     * Set the RLS session variables on a single connection. Not swallowed: if the
     * connection cannot be opened the read that needed it would fail anyway, and a
     * loud failure is safer than the silent zero-row reads the eager approach hid.
     */
    public function applyTo(Connection $connection): void
    {
        $pdo  = $connection->getPdo();
        $id   = $pdo->quote($this->userId);
        $role = $pdo->quote($this->userRole);
        $connection->unprepared("SET SESSION app.current_user_id = {$id}");
        $connection->unprepared("SET SESSION app.user_role = {$role}");
    }
}
