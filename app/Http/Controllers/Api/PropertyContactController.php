<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Lease\LeaseService;
use App\Services\Property\PropertyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Member contact directory for a property — landowner and property managers
 * (derived from platform accounts) plus local law enforcement, game warden,
 * emergency and custom contacts the landowner added. Gated to users with an
 * active lease on the property (see SEC-024); unauthorized callers get 404,
 * never 403, so property existence is not revealed.
 */
class PropertyContactController extends Controller
{
    public function __construct(
        private readonly PropertyService $propertyService,
        private readonly LeaseService $leaseService,
    ) {}

    /**
     * GET /api/v1/properties/{id}/contacts
     */
    public function index(Request $request, string $id): JsonResponse
    {
        if (! $this->leaseService->userHasActiveLeaseForProperty($request->user()->id, $id)) {
            return response()->json(['error' => 'Not found'], 404);
        }

        return response()->json([
            'data' => $this->propertyService->getContactDirectory($id),
        ]);
    }
}
