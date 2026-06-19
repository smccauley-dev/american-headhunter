<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
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

        return inertia('Public/Pricing', [
            'groups' => $groups,
        ]);
    }
}
