<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class FeatureFlagCheck
{
    /**
     * Block a route if a feature flag is disabled.
     * Usage in routes: ->middleware('feature:auction_module')
     */
    public function handle(Request $request, Closure $next, string $flagKey): Response
    {
        if (! $this->isEnabled($flagKey)) {
            abort(404);
        }

        return $next($request);
    }

    private function isEnabled(string $flagKey): bool
    {
        return Cache::store('valkey')->remember(
            "feature_flag:{$flagKey}",
            now()->addMinutes(5),
            function () use ($flagKey) {
                try {
                    $flag = DB::connection('platform')
                        ->table('feature_flags')
                        ->where('flag_key', $flagKey)
                        ->first(['is_enabled', 'rollout_pct']);

                    if (! $flag || ! $flag->is_enabled) {
                        return false;
                    }

                    // Full rollout
                    if ($flag->rollout_pct >= 100) {
                        return true;
                    }

                    // Partial rollout — hash-based bucketing
                    return (crc32($flagKey) % 100) < $flag->rollout_pct;
                } catch (\Throwable) {
                    return true; // Fail open — don't block users if DB is down
                }
            }
        );
    }
}
