<?php

namespace App\Http\Middleware;

use App\Database\RlsContext;
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

        // SEC-055: arm lazy injection rather than eagerly opening every database.
        // The ConnectionEstablished listener (DatabaseServiceProvider) applies the
        // context to each RLS-bearing connection the first time it is actually
        // opened, so a request never opens databases it does not use. Force-opening
        // all ~14 here previously exhausted PostgreSQL's connection slots under
        // load and left the loser without context — silent zero-row RLS reads.
        $context = app(RlsContext::class);
        $context->set($userId, $userRole);

        // Any connections already opened before this middleware ran (e.g. the
        // identity connection used to resolve the role above) missed the listener
        // because the context was not yet armed — apply to them now. This only
        // touches connections that are already resolved; it does not open new ones.
        foreach (DB::getConnections() as $name => $connection) {
            if ($context->appliesTo($name)) {
                $context->applyTo($connection);
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
