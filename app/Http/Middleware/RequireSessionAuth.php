<?php

namespace App\Http\Middleware;

use App\Models\Identity\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireSessionAuth
{
    /** Re-verify the account is still active at most once per this many seconds. */
    private const RECHECK_INTERVAL = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $userId = $request->session()->get('auth.user_id');

        if (! $userId) {
            return redirect()->route('auth.login');
        }

        // SEC-037: a session token alone is not enough — re-confirm the account is
        // still active so a suspended or deleted user loses access immediately
        // rather than at natural session expiry. Result is cached in-session for
        // RECHECK_INTERVAL seconds to avoid a DB hit on every request.
        $lastChecked = (int) $request->session()->get('auth.active_checked_at', 0);

        if (now()->timestamp - $lastChecked >= self::RECHECK_INTERVAL) {
            $stillActive = User::on('identity')
                ->whereKey($userId)
                ->where('status', 'active')
                ->exists();

            if (! $stillActive) {
                $request->session()->forget(['auth.user_id', 'auth.active_checked_at']);

                return redirect()->route('auth.login');
            }

            $request->session()->put('auth.active_checked_at', now()->timestamp);
        }

        return $next($request);
    }
}
