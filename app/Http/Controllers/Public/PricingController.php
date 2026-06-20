<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Identity\User;
use App\Services\Platform\PlanService;
use Illuminate\Support\Facades\Storage;
use Inertia\Response;

class PricingController extends Controller
{
    public function __construct(private readonly PlanService $planService) {}

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
        // subscribe to so only their own account type's paid plans offer checkout.
        $currentAccountType = null;
        if ($userId = session('auth.user_id')) {
            $currentAccountType = User::find($userId)?->account_type;
        }

        return inertia('Public/Pricing', [
            'groups'               => $groups,
            'current_account_type' => $currentAccountType,
        ]);
    }
}
