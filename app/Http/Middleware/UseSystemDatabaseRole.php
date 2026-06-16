<?php

namespace App\Http\Middleware;

use App\Database\ConnectionRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SEC-043 — run this request's app database connections as the trusted
 * `ah_system` (BYPASSRLS) role instead of the user-facing `ah_runtime`.
 *
 * Applied to HTTP paths that cannot satisfy per-user RLS policies because they
 * run before (or independent of) an authenticated user context:
 *   - the auth bootstrap routes (login, register, verify, MFA, recovery, reset)
 *   - the Filament admin panel (trusted staff CRUD across all users)
 *
 * Purges so the swap takes effect even if a connection was already opened
 * earlier in the request (e.g. by the RLS-context middleware).
 */
class UseSystemDatabaseRole
{
    public function handle(Request $request, Closure $next): Response
    {
        ConnectionRole::useSystem(purge: true);

        return $next($request);
    }
}
