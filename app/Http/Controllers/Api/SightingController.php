<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Wildlife\WildlifeSighting;
use App\Services\Wildlife\SightingService;
use App\Services\Wildlife\WildlifeAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mobile wildlife-sighting API. Same DB-5 standing boundary as harvest logging
 * (enforced in SightingService for the write, and re-checked here for the
 * lease-scoped read); sightings carry no quota or CWD gate. Reads need
 * hunter:read; the write needs hunter:harvest. Idempotent on local_record_id.
 */
class SightingController extends Controller
{
    public function __construct(
        private readonly SightingService $sightings,
        private readonly WildlifeAccess $access,
    ) {}

    /** The caller's own sightings on a lease they have standing on, newest first. */
    public function index(Request $request, string $lease): JsonResponse
    {
        $this->access->assertLeaseStanding($request->user()->id, $lease);

        $rows = WildlifeSighting::on('wildlife')
            ->where('user_id', $request->user()->id)
            ->where('lease_id', $lease)
            ->whereNull('deleted_at')
            ->orderByDesc('sighting_date')
            ->get()
            ->map(fn (WildlifeSighting $s) => $this->shape($s))
            ->all();

        return response()->json(['sightings' => $rows]);
    }

    /** Log a sighting against a lease the caller has standing on. */
    public function store(Request $request, string $lease): JsonResponse
    {
        $data = $request->validate([
            'species_code' => ['required', 'string', 'max:60'],
            'sighting_date' => ['required', 'date'],
            'sighting_time' => ['nullable', 'date_format:H:i'],
            'count' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'gps_accuracy_m' => ['nullable', 'numeric', 'min:0'],
            'local_record_id' => ['nullable', 'uuid'],
        ]);

        $sighting = $this->sightings->log($request->user()->id, $lease, $data);

        return response()->json(['sighting' => $this->shape($sighting)], $sighting->wasRecentlyCreated ? 201 : 200);
    }

    private function shape(WildlifeSighting $s): array
    {
        return [
            'id' => $s->id,
            'lease_id' => $s->lease_id,
            'property_id' => $s->property_id,
            'species_code' => $s->species_code,
            'sighting_date' => $s->sighting_date?->toDateString(),
            'sighting_time' => $s->sighting_time,
            'count' => (int) $s->count,
            'notes' => $s->notes,
            'location_geospatial_id' => $s->location_geospatial_id,
            'logged_at' => $s->created_at?->toIso8601String(),
        ];
    }
}
