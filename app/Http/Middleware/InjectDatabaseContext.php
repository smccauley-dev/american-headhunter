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
     *   USING (user_id = NULLIF(current_setting('app.current_user_id', true), '')::UUID)
     *
     * Runs inside the web and api groups (after the session is started) so the
     * authenticated user can be resolved. Uses `true` as the second arg to
     * current_setting() so unset variables return NULL rather than throwing.
     *
     * SEC-043: the web portals authenticate via a custom session key
     * (`auth.user_id`), NOT Laravel's guard — so $request->user() is null for
     * web requests and only populated for token (Sanctum) API requests. Derive
     * the id from whichever is present. This was previously masked because the
     * app connected as the table owner (ah_app) and bypassed RLS entirely; now
     * that user-facing requests run as ah_runtime, an empty context would make
     * every RLS-protected read return zero rows.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tokenUser = $request->user();                              // Sanctum / API
        $userId    = $tokenUser?->id
            // Stateless API requests have no session store; guard before reading.
            ?? ($request->hasSession() ? $request->session()->get('auth.user_id') : null)
            ?? '';

        $userRole = $this->resolveRole($tokenUser, $userId);

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
                $conn       = DB::connection($connection);
                $pdo        = $conn->getPdo();
                $quotedId   = $pdo->quote($userId);
                $quotedRole = $pdo->quote($userRole);
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

    /**
     * Resolve the user's role name for RLS. For token requests the model is
     * already loaded; for web requests look it up directly from user_roles/roles
     * (neither table is RLS-protected, so this works regardless of context).
     */
    private function resolveRole(mixed $tokenUser, string $userId): string
    {
        if ($tokenUser) {
            return $tokenUser->roles->first()?->name ?? '';
        }

        if ($userId === '') {
            return '';
        }

        try {
            return (string) (DB::connection('identity')->table('user_roles')
                ->join('roles', 'roles.id', '=', 'user_roles.role_id')
                ->where('user_roles.user_id', $userId)
                ->value('roles.name') ?? '');
        } catch (\Throwable) {
            return '';
        }
    }
}
