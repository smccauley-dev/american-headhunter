<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Documents\Document;
use App\Models\Property\PropertyMapImage;
use App\Models\Property\PropertyMapMarker;
use App\Services\Lease\LeaseService;
use App\Services\Property\PropertyMapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Member field-ops map data — map images and their markers (stands, blinds,
 * cameras, gates). This is NOT public: markers carry precise on-property GPS,
 * so every endpoint is gated to users with an active lease on the property
 * (see SEC-024 and LeaseService::userHasActiveLeaseForProperty). Unauthorized
 * callers get 404, never 403, so property existence is not revealed.
 */
class PropertyMapController extends Controller
{
    public function __construct(
        private readonly PropertyMapService $mapService,
        private readonly LeaseService $leaseService,
    ) {}

    /**
     * GET /api/v1/properties/{id}/map
     * All live map images for the property, each with its markers.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        if (! $this->leaseService->userHasActiveLeaseForProperty($request->user()->id, $id)) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $images = $this->mapService->getMapImages($id)->load('markers');

        return response()->json([
            'data' => [
                'property_id' => $id,
                'images' => $images->map(fn (PropertyMapImage $img) => [
                    'id' => $img->id,
                    'image_url' => route('api.property-maps.image', ['id' => $id, 'documentId' => $img->document_id]),
                    'is_boundary' => (bool) $img->is_boundary,
                    'description' => $img->description,
                    'sort_order' => $img->sort_order,
                    // Lessees see the reference point regardless of the public toggle.
                    'reference_point' => ($img->latitude !== null && $img->longitude !== null)
                        ? ['lat' => $img->latitude, 'lng' => $img->longitude]
                        : null,
                    'markers' => $img->markers->map(fn (PropertyMapMarker $m) => [
                        'id' => $m->id,
                        'label' => $m->label,
                        'type' => $m->marker_type,
                        'type_label' => PropertyMapMarker::TYPES[$m->marker_type] ?? $m->marker_type,
                        'color' => $m->displayColor(),
                        // Position on the raster image (web overlay) ...
                        'x_percent' => $m->x_percent,
                        'y_percent' => $m->y_percent,
                        // ... and GPS for native-map rendering, when set.
                        'lat' => $m->latitude,
                        'lng' => $m->longitude,
                        'notes' => $m->notes,
                    ])->values(),
                ])->values(),
            ],
        ]);
    }

    /**
     * GET /api/v1/properties/{id}/map-images/{documentId}
     * Serve a map image's bytes to an active lessee. Mirrors the admin/public
     * image routes but is authorised by lease membership rather than the web
     * guard, so native clients can load it with their bearer token.
     */
    public function image(Request $request, string $id, string $documentId): StreamedResponse
    {
        if (! $this->leaseService->userHasActiveLeaseForProperty($request->user()->id, $id)) {
            abort(404);
        }

        $belongsToProperty = PropertyMapImage::where('property_id', $id)
            ->where('document_id', $documentId)
            ->whereNull('deleted_at')
            ->exists();
        abort_unless($belongsToProperty, 404);

        $doc = Document::on('documents')->findOrFail($documentId);
        $disk = config('filesystems.defaults.documents', 'local');

        return Storage::disk($disk)->response(
            $doc->storage_key,
            $doc->original_filename,
            ['Content-Type' => $doc->mime_type ?? 'image/jpeg', 'Cache-Control' => 'private, max-age=300'],
        );
    }
}
