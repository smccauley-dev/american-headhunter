<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lease\CheckIn;
use App\Models\Lease\Lease;
use App\Services\Lease\CheckInService;
use App\Services\Property\PropertyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mobile field check-in / check-out. Runs as the Sanctum member (ah_runtime);
 * standing to check in against a lease is enforced in CheckInService::checkIn
 * (403 for anyone who is neither the lessee nor an approved hunter). GPS is
 * advisory only — a supplied point is used to warn on a boundary miss and to
 * attach the nearest stand, never to block. Requires the hunter:checkin ability.
 */
class CheckInController extends Controller
{
    public function __construct(
        private readonly CheckInService $checkIns,
        private readonly PropertyService $properties,
    ) {}

    /** The caller's currently-open check-in across any active lease, or null. */
    public function active(Request $request): JsonResponse
    {
        $open = $this->checkIns->getOpenForUser($request->user()->id);

        return response()->json(['active' => $open ? $this->shape($open) : null]);
    }

    /** Check in against a lease. Idempotent per (lease, user). */
    public function checkIn(Request $request, string $lease): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $result = $this->checkIns->checkIn(
            $request->user()->id,
            $lease,
            isset($data['lat']) ? (float) $data['lat'] : null,
            isset($data['lng']) ? (float) $data['lng'] : null,
        );

        return response()->json([
            'check_in' => $this->shape($result['check_in']),
            'within_boundary' => $result['within_boundary'],
            'new' => $result['new'],
        ], $result['new'] ? 201 : 200);
    }

    /** Close the caller's open check-in on a lease. 200 with the closed record, or 404 if none open. */
    public function checkOut(Request $request, string $lease): JsonResponse
    {
        $closed = $this->checkIns->checkOut($request->user()->id, $lease);

        if (! $closed) {
            return response()->json(['message' => 'You have no open check-in on this lease.'], 404);
        }

        return response()->json(['check_in' => $this->shape($closed)]);
    }

    /** JSON shape for a check-in, with best-effort property context for the "in the field" banner. */
    private function shape(CheckIn $checkIn): array
    {
        $property = rescue(function () use ($checkIn) {
            $lease = Lease::on('lease')->find($checkIn->lease_id);

            return $lease ? $this->properties->find($lease->property_id) : null;
        }, null);

        return [
            'id' => $checkIn->id,
            'lease_id' => $checkIn->lease_id,
            'stand_location_id' => $checkIn->stand_location_id,
            'checked_in_at' => $checkIn->checked_in_at?->toIso8601String(),
            'checked_out_at' => $checkIn->checked_out_at?->toIso8601String(),
            'open' => $checkIn->checked_out_at === null,
            'property' => $property ? [
                'id' => $property->id,
                'title' => $property->title,
                'county' => $property->county,
                'state_code' => $property->state_code,
            ] : null,
        ];
    }
}
