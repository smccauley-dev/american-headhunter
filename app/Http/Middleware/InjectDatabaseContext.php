<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class InjectDatabaseContext
{
    /**
     * Set PostgreSQL session-level variables for RLS policies.
     * These variables are read by policies like:
     *   USING (user_id = current_setting('app.current_user_id', true)::UUID)
     *
     * Runs on every request. Uses `true` as the second arg to current_setting()
     * so unset variables return NULL rather than throwing an error.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user     = $request->user();
        $userId   = $user?->id ?? '';
        $userRole = $user?->roles->first()?->name ?? '';

        // RLS context is injected only for connections that carry (or may carry)
        // user-scoped row-level-security policies reachable from an HTTP request.
        //
        // SEC-023/D02 — the following connections are INTENTIONALLY excluded:
        //   - audit (DB 9):        append-only, immutable; no user-scoped RLS.
        //   - analytics (DB 8):    read-only reporting via readonly_user; no RLS.
        //   - analytics_etl (DB 8) and research (DB 14): touched only by ETL job
        //     classes, never through the HTTP layer, so no request-time context
        //     applies.
        // If a user-scoped RLS policy is ever added to one of these databases, it
        // MUST be added to the list below (and ETL writers given an explicit
        // context-setting step), or its policies will silently see a NULL user.
        $connections = [
            'identity', 'property', 'property_read',
            'lease', 'billing', 'wildlife', 'wildlife_read',
            'commerce', 'communications',
            'incidents', 'documents', 'platform',
            'geospatial', 'geospatial_read',
        ];

        foreach ($connections as $connection) {
            try {
                $pdo        = DB::connection($connection)->getPdo();
                $quotedId   = $pdo->quote($userId);
                $quotedRole = $pdo->quote($userRole);
                $conn = DB::connection($connection);
                $conn->unprepared("SET SESSION app.current_user_id = {$quotedId}");
                $conn->unprepared("SET SESSION app.user_role = {$quotedRole}");
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('RLS context injection failed', [
                    'connection' => $connection,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        return $next($request);
    }
}
