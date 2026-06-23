<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Identity\User;
use App\Services\Platform\EntitlementService;
use App\Services\Platform\PlanService;
use Illuminate\Support\Facades\Storage;
use Inertia\Response;

class PricingController extends Controller
{
    public function __construct(
        private readonly PlanService $planService,
        private readonly EntitlementService $entitlements,
    ) {}

    public function index(): Response
    {
        $groups = $this->planService->publicPricing();

        // Resolve stored header-image paths to public URLs at the edge; the
        // service keeps raw paths so the cached payload stays disk-agnostic.
        foreach ($groups as $accountType => $plans) {
            $groups[$accountType] = array_map(function (array $plan): array {
                $plan['header_image_url'] = $plan['header_image_path']
                    ? Storage::disk('public')->url($plan['header_image_path'])
                    : null;
                unset($plan['header_image_path']);

                return $plan;
            }, $plans);
        }

        // When logged in, tell the page which group the member can actually
        // subscribe to so only their own account type's paid plans offer checkout,
        // plus their current paid plan so we can mark it / offer a switch instead.
        $currentAccountType   = null;
        $currentPlanKey       = null;
        $hasActiveSubscription = false;
        if ($userId = session('auth.user_id')) {
            if ($user = User::find($userId)) {
                $currentAccountType = $user->account_type;

                $membership            = $this->entitlements->currentMembership($user);
                $hasActiveSubscription = ($membership['source'] ?? null) === 'subscription' && empty($membership['is_free']);
                $currentPlanKey        = $hasActiveSubscription ? ($membership['plan_key'] ?? null) : null;
            }
        }

        return inertia('Public/Pricing', [
            'groups'                 => $groups,
            'callouts'               => $this->planService->publicCallouts(),
            'current_account_type'   => $currentAccountType,
            'current_plan_key'       => $currentPlanKey,
            'has_active_subscription' => $hasActiveSubscription,
        ]);
    }
}
