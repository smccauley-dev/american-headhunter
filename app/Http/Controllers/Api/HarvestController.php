<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Wildlife\HarvestLog;
use App\Models\Wildlife\HarvestQuota;
use App\Services\Wildlife\HarvestService;
use App\Services\Wildlife\QuotaService;
use App\Services\Wildlife\WildlifeAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mobile harvest-logging API. Runs as the Sanctum member (ah_runtime), but DB 5
 * has no RLS — the WildlifeAccess standing check inside HarvestService is the
 * entire authorization boundary, re-enforced on every call. Reads need
 * hunter:read; the write needs hunter:harvest.
 *
 * The POST is idempotent on client-minted local_record_id (offline replay
 * returns the original row, no quota re-claim) — the whole point of the mobile
 * write queue. Quota (409) and CWD acknowledgment (422) are authoritatively
 * re-checked server-side at sync, regardless of what the offline client believed.
 */
class HarvestController extends Controller
{
    public function __construct(
        private readonly HarvestService $harvests,
        private readonly QuotaService $quotas,
        private readonly WildlifeAccess $access,
    ) {}

    /** The caller's own harvest logs, newest first. */
    public function index(Request $request): JsonResponse
    {
        $rows = $this->harvests->listForUser($request->user()->id)
            ->map(fn (HarvestLog $h) => $this->shape($h))
            ->all();

        return response()->json(['harvests' => $rows]);
    }

    /** One harvest the caller may read (own / standing / manager). 404 otherwise. */
    public function show(Request $request, string $harvest): JsonResponse
    {
        $log = $this->harvests->findForUser($request->user()->id, $harvest);

        return response()->json(['harvest' => $this->shape($log)]);
    }

    /** Log a harvest against a lease the caller has standing on. */
    public function store(Request $request, string $lease): JsonResponse
    {
        $data = $request->validate([
            'species_code' => ['required', 'string', 'max:60'],
            'harvest_date' => ['required', 'date'],
            'weapon_type' => ['required', 'string', 'max:40'],
            'harvest_time' => ['nullable', 'date_format:H:i'],
            'antler_score' => ['nullable', 'numeric'],
            'weight_lbs' => ['nullable', 'numeric'],
            'age_estimate' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_public' => ['nullable', 'boolean'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'gps_accuracy_m' => ['nullable', 'numeric', 'min:0'],
            'hide_location_from_members' => ['nullable', 'boolean'],
            'cwd_acknowledged' => ['nullable', 'boolean'],
            'local_record_id' => ['nullable', 'uuid'],
        ]);

        $log = $this->harvests->log($request->user()->id, $lease, $data);

        // wasRecentlyCreated distinguishes a fresh insert from an offline replay
        // that returned the original row — 201 vs 200 for the sync client.
        return response()->json(['harvest' => $this->shape($log)], $log->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Attach a field photo to one of the caller's harvests. The image is
     * re-encoded to strip EXIF GPS (SEC-024) and virus-scanned before it is
     * servable — the returned document sits in 'processing' until the scan clears.
     * The photo is also mirrored into the caller's profile Photos gallery.
     *
     * keep_location opts out of the EXIF strip: the original bytes are stored
     * (still validated + scanned) and the gallery mirror is flagged
     * is_location_private so it is never publicly servable (SEC-061).
     */
    public function storePhoto(Request $request, string $harvest): JsonResponse
    {
        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:15360'],
            'keep_location' => ['nullable', 'boolean'],
        ]);

        $document = $this->harvests->attachFieldPhoto(
            $request->user()->id,
            $harvest,
            $request->file('photo'),
            $request->boolean('keep_location'),
        );

        return response()->json([
            'document' => [
                'id' => $document->id,
                'status' => $document->status,
            ],
        ], 201);
    }

    /**
     * Remaining tags per species for a lease — the offline quota cache + UI. The
     * standing check runs here (this route does not pass through HarvestService).
     */
    public function quota(Request $request, string $lease): JsonResponse
    {
        $leaseModel = $this->access->assertLeaseStanding($request->user()->id, $lease);
        $year = (int) $request->query('year', (string) now()->year);

        $rows = $this->quotas->listForLease($leaseModel->property_id, $lease, $year)
            ->map(fn (HarvestQuota $q) => [
                'species_code' => $q->species_code,
                'season_year' => (int) $q->season_year,
                'max_harvest' => (int) $q->max_harvest,
                'current_harvest' => (int) $q->current_harvest,
                'remaining' => $q->remaining(),
                'scope' => $q->lease_id !== null ? 'lease' : 'property',
            ])
            ->values()
            ->all();

        return response()->json([
            'lease_id' => $lease,
            'season_year' => $year,
            'quotas' => $rows,
        ]);
    }

    /**
     * Compact JSON for a harvest. Precise GPS lives only in DB 13 (SEC-024) — the
     * opaque location reference is returned, never raw coordinates.
     */
    private function shape(HarvestLog $h): array
    {
        return [
            'id' => $h->id,
            'lease_id' => $h->lease_id,
            'property_id' => $h->property_id,
            'species_code' => $h->species_code,
            'harvest_date' => $h->harvest_date?->toDateString(),
            'harvest_time' => $h->harvest_time,
            'weapon_type' => $h->weapon_type,
            'antler_score' => $h->antler_score,
            'weight_lbs' => $h->weight_lbs,
            'age_estimate' => $h->age_estimate,
            'is_public' => (bool) $h->is_public,
            'notes' => $h->notes,
            'location_geospatial_id' => $h->location_geospatial_id,
            'logged_at' => $h->created_at?->toIso8601String(),
        ];
    }
}
