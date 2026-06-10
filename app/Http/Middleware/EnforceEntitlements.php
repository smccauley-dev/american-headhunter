<?php

namespace App\Http\Middleware;

use App\Services\Platform\EntitlementService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceEntitlements
{
    public function __construct(
        private readonly EntitlementService $entitlements,
    ) {}

    /**
     * Gate a route behind a subscription entitlement.
     * Usage: ->middleware('entitlement:trail_camera_integration')
     *
     * Returns 403 if the authenticated user's plan does not include the feature.
     */
    public function handle(Request $request, Closure $next, string $featureKey): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        if (! $this->entitlements->can($user, $featureKey)) {
            abort(403, 'Your plan does not include this feature.');
        }

        return $next($request);
    }
}
