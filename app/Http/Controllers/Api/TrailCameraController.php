<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Wildlife\TrailCamera;
use App\Models\Wildlife\TrailCameraPhoto;
use App\Services\Wildlife\TrailCameraService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mobile trail-camera read API. Gated twice inside TrailCameraService: the DB-5
 * standing check AND the trail_camera_integration entitlement (403 when the plan
 * doesn't include it). Read-only this slice; vendor-feed sync and AI species
 * tagging are Phase 6.4 jobs.
 */
class TrailCameraController extends Controller
{
    public function __construct(
        private readonly TrailCameraService $cameras,
    ) {}

    /** Cameras on a property the caller may access. */
    public function index(Request $request, string $property): JsonResponse
    {
        $rows = $this->cameras->listForProperty($request->user(), $property)
            ->map(fn (TrailCamera $c) => [
                'id' => $c->id,
                'property_id' => $c->property_id,
                'lease_id' => $c->lease_id,
                'name' => $c->name,
                'model' => $c->model,
                'status' => $c->status,
                'location_geospatial_id' => $c->location_geospatial_id,
                'last_photo_at' => $c->last_photo_at?->toIso8601String(),
            ])
            ->all();

        return response()->json(['cameras' => $rows]);
    }

    /** Photos for a camera the caller may access. 404 when the camera is unknown. */
    public function photos(Request $request, string $camera): JsonResponse
    {
        $rows = $this->cameras->photosFor($request->user(), $camera)
            ->map(fn (TrailCameraPhoto $p) => [
                'id' => $p->id,
                'camera_id' => $p->camera_id,
                'document_id' => $p->document_id,
                'taken_at' => $p->taken_at?->toIso8601String(),
                'species_detected' => $p->species_detected,
                'ai_confidence' => $p->ai_confidence,
                'is_flagged' => (bool) $p->is_flagged,
            ])
            ->all();

        return response()->json(['photos' => $rows]);
    }
}
