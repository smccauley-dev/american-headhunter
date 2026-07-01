<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Wildlife\FishingHarvestLog;
use App\Services\Wildlife\FishingHarvestService;
use App\Services\Wildlife\WildlifeAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mobile fishing-catch API. Same DB-5 standing boundary as harvest logging
 * (enforced in FishingHarvestService for the write, and re-checked here for the
 * lease-scoped read); no quota or CWD. Reads need hunter:read; the write needs
 * hunter:harvest. Idempotent on local_record_id.
 */
class FishingController extends Controller
{
    public function __construct(
        private readonly FishingHarvestService $fishing,
        private readonly WildlifeAccess $access,
    ) {}

    /** The caller's own catches on a lease they have standing on, newest first. */
    public function index(Request $request, string $lease): JsonResponse
    {
        $this->access->assertLeaseStanding($request->user()->id, $lease);

        $rows = FishingHarvestLog::on('wildlife')
            ->where('user_id', $request->user()->id)
            ->where('lease_id', $lease)
            ->whereNull('deleted_at')
            ->orderByDesc('catch_date')
            ->get()
            ->map(fn (FishingHarvestLog $c) => $this->shape($c))
            ->all();

        return response()->json(['catches' => $rows]);
    }

    /** Log a catch against a lease the caller has standing on. */
    public function store(Request $request, string $lease): JsonResponse
    {
        $data = $request->validate([
            'species_code' => ['required', 'string', 'max:60'],
            'catch_date' => ['required', 'date'],
            'catch_time' => ['nullable', 'date_format:H:i'],
            'length_inches' => ['nullable', 'numeric', 'min:0'],
            'weight_lbs' => ['nullable', 'numeric', 'min:0'],
            'catch_and_release' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_public' => ['nullable', 'boolean'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'gps_accuracy_m' => ['nullable', 'numeric', 'min:0'],
            'local_record_id' => ['nullable', 'uuid'],
        ]);

        $catch = $this->fishing->log($request->user()->id, $lease, $data);

        return response()->json(['catch' => $this->shape($catch)], $catch->wasRecentlyCreated ? 201 : 200);
    }

    private function shape(FishingHarvestLog $c): array
    {
        return [
            'id' => $c->id,
            'lease_id' => $c->lease_id,
            'property_id' => $c->property_id,
            'species_code' => $c->species_code,
            'catch_date' => $c->catch_date?->toDateString(),
            'catch_time' => $c->catch_time,
            'length_inches' => $c->length_inches,
            'weight_lbs' => $c->weight_lbs,
            'catch_and_release' => (bool) $c->catch_and_release,
            'is_public' => (bool) $c->is_public,
            'notes' => $c->notes,
            'location_geospatial_id' => $c->location_geospatial_id,
            'logged_at' => $c->created_at?->toIso8601String(),
        ];
    }
}
