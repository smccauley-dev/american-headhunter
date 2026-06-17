<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Models\Property\PropertyMapImage;
use App\Models\Property\PropertyMapMarker;
use App\Services\Property\PropertyMapService;
use App\Services\Property\PropertyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Landowner front-end property maps (member portal) — the Map tab of the details
 * hub. Image-based maps: upload images, pick the boundary, and place / drag /
 * edit / delete markers by percent coordinate. Everything is scoped through
 * PropertyService::userCanManageProperty (the properties table has no RLS policy);
 * map images and markers are re-scoped to the property so foreign ids 404. The
 * marker mutations are XHR (Inertia) calls that preserve state, so the React
 * editor can drag without a full page reload.
 */
class PropertyMapController extends Controller
{
    public function __construct(
        private readonly PropertyService $properties,
        private readonly PropertyMapService $maps,
    ) {}

    /**
     * Serve a map image to a manager of the property. Unlike the public
     * property-maps route this serves non-boundary images and images on draft
     * properties, so the owner can build the map before going live.
     */
    public function serveImage(string $property, string $documentId)
    {
        $this->authorizeManage($property);

        // Deleted images are still served so the recovery gallery can show
        // thumbnails; scoping to the property is the access boundary.
        $belongs = PropertyMapImage::on('property_read')
            ->where('property_id', $property)
            ->where('document_id', $documentId)
            ->exists();
        abort_unless($belongs, 404);

        $doc  = \App\Models\Documents\Document::on('documents')->findOrFail($documentId);
        $disk = \Illuminate\Support\Facades\Storage::disk(config('filesystems.defaults.documents', 'local'));
        abort_unless($disk->exists($doc->storage_key), 404);

        return $disk->response(
            $doc->storage_key,
            $doc->original_filename,
            ['Content-Type' => $doc->mime_type ?? 'image/jpeg', 'Cache-Control' => 'private, max-age=3600'],
        );
    }

    public function storeImage(Request $request, string $property): RedirectResponse
    {
        $this->authorizeManage($property);

        $data = $request->validate([
            'images'      => 'required|array|max:10',
            'images.*'    => 'image|max:15360', // 15 MB
            'description' => 'nullable|string|max:255',
            'import_exif' => 'boolean',
        ]);

        $uploaded = 0;
        foreach ($data['images'] as $file) {
            try {
                $this->maps->addMapImage(
                    $property,
                    $file,
                    $data['description'] ?? null,
                    (bool) ($data['import_exif'] ?? true),
                );
                $uploaded++;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return back()->with(
            $uploaded > 0 ? 'success' : 'error',
            $uploaded > 0
                ? ($uploaded === 1 ? 'Map image uploaded.' : "{$uploaded} map images uploaded.")
                : 'Map upload failed.',
        );
    }

    public function setBoundary(string $property, string $mapImage): RedirectResponse
    {
        $this->authorizeOwnsImage($property, $mapImage);

        $this->maps->setBoundaryImage($mapImage);

        return back()->with('success', 'Boundary map updated.');
    }

    /** Edit Details — description, coordinates, public-coords toggle, boundary. */
    public function updateImage(Request $request, string $property, string $mapImage): RedirectResponse
    {
        $this->authorizeOwnsImage($property, $mapImage);

        $data = $request->validate([
            'description'          => 'nullable|string|max:255',
            'latitude'             => 'nullable|numeric|min:-90|max:90',
            'longitude'            => 'nullable|numeric|min:-180|max:180',
            'show_coords_publicly' => 'boolean',
            'is_boundary'          => 'boolean',
        ]);

        $this->maps->updateMapImageDetails(
            $mapImage,
            $data['description'] ?? null,
            isset($data['latitude']) ? (float) $data['latitude'] : null,
            isset($data['longitude']) ? (float) $data['longitude'] : null,
            (bool) ($data['show_coords_publicly'] ?? false),
        );

        if (! empty($data['is_boundary'])) {
            $this->maps->setBoundaryImage($mapImage);
        }

        return back()->with('success', 'Map image updated.');
    }

    /** Download the original map image file for a manager of the property. */
    public function downloadImage(string $property, string $mapImage)
    {
        $this->authorizeOwnsImage($property, $mapImage);

        $image = PropertyMapImage::on('property_read')->findOrFail($mapImage);
        $doc   = \App\Models\Documents\Document::on('documents')->findOrFail($image->document_id);
        $disk  = config('filesystems.defaults.documents', 'local');

        return \Illuminate\Support\Facades\Storage::disk($disk)->download(
            $doc->storage_key,
            $doc->original_filename,
        );
    }

    /** Restore a soft-deleted map image. */
    public function restoreImage(string $property, string $mapImage): RedirectResponse
    {
        $this->authorizeManage($property);

        $belongs = PropertyMapImage::on('property_read')
            ->where('id', $mapImage)
            ->where('property_id', $property)
            ->whereNotNull('deleted_at')
            ->exists();
        abort_unless($belongs, 404);

        $this->maps->restoreMapImage($mapImage);

        return back()->with('success', 'Map image restored.');
    }

    public function destroyImage(string $property, string $mapImage): RedirectResponse
    {
        $this->authorizeOwnsImage($property, $mapImage);

        $this->maps->deleteMapImage($mapImage);

        return back()->with('success', 'Map image deleted.');
    }

    public function addMarker(Request $request, string $property, string $mapImage): RedirectResponse
    {
        $this->authorizeOwnsImage($property, $mapImage);

        $data = $request->validate([
            'label'       => 'required|string|max:120',
            'marker_type' => ['required', Rule::in(array_keys(PropertyMapMarker::TYPES))],
            'x_percent'   => 'required|numeric|min:0|max:100',
            'y_percent'   => 'required|numeric|min:0|max:100',
            'latitude'    => 'nullable|numeric|min:-90|max:90',
            'longitude'   => 'nullable|numeric|min:-180|max:180',
            'color'       => 'nullable|string|regex:/^#[0-9a-fA-F]{6}$/',
            'notes'       => 'nullable|string|max:500',
        ]);

        $this->maps->addMarker(
            $mapImage,
            $data['label'],
            $data['marker_type'],
            (float) $data['x_percent'],
            (float) $data['y_percent'],
            isset($data['latitude']) ? (float) $data['latitude'] : null,
            isset($data['longitude']) ? (float) $data['longitude'] : null,
            $data['notes'] ?? null,
            $data['color'] ?? null,
        );

        return back()->with('success', 'Marker added.');
    }

    public function updateMarker(Request $request, string $property, string $marker): RedirectResponse
    {
        $this->authorizeOwnsMarker($property, $marker);

        $data = $request->validate([
            'label'       => 'required|string|max:120',
            'marker_type' => ['required', Rule::in(array_keys(PropertyMapMarker::TYPES))],
            'latitude'    => 'nullable|numeric|min:-90|max:90',
            'longitude'   => 'nullable|numeric|min:-180|max:180',
            'color'       => 'nullable|string|regex:/^#[0-9a-fA-F]{6}$/',
            'notes'       => 'nullable|string|max:500',
        ]);

        $this->maps->updateMarker(
            $marker,
            $data['label'],
            $data['marker_type'],
            isset($data['latitude']) ? (float) $data['latitude'] : null,
            isset($data['longitude']) ? (float) $data['longitude'] : null,
            $data['notes'] ?? null,
            $data['color'] ?? null,
        );

        return back()->with('success', 'Marker updated.');
    }

    public function moveMarker(Request $request, string $property, string $marker): RedirectResponse
    {
        $this->authorizeOwnsMarker($property, $marker);

        $data = $request->validate([
            'x_percent' => 'required|numeric|min:0|max:100',
            'y_percent' => 'required|numeric|min:0|max:100',
        ]);

        $this->maps->moveMarker($marker, (float) $data['x_percent'], (float) $data['y_percent']);

        return back();
    }

    public function destroyMarker(string $property, string $marker): RedirectResponse
    {
        $this->authorizeOwnsMarker($property, $marker);

        $this->maps->deleteMarker($marker);

        return back()->with('success', 'Marker deleted.');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function authorizeManage(string $propertyId): void
    {
        $userId = session('auth.user_id');
        abort_unless($this->properties->userCanManageProperty($userId, $propertyId), 404);
    }

    private function authorizeOwnsImage(string $propertyId, string $mapImageId): void
    {
        $this->authorizeManage($propertyId);

        $belongs = PropertyMapImage::on('property_read')
            ->where('id', $mapImageId)
            ->where('property_id', $propertyId)
            ->whereNull('deleted_at')
            ->exists();

        abort_unless($belongs, 404);
    }

    private function authorizeOwnsMarker(string $propertyId, string $markerId): void
    {
        $this->authorizeManage($propertyId);

        $belongs = PropertyMapMarker::on('property_read')
            ->where('property_map_markers.id', $markerId)
            ->whereNull('property_map_markers.deleted_at')
            ->whereExists(function ($q) use ($propertyId) {
                $q->select(\Illuminate\Support\Facades\DB::raw(1))
                    ->from('property_map_images as pmi')
                    ->whereColumn('pmi.id', 'property_map_markers.map_image_id')
                    ->where('pmi.property_id', $propertyId)
                    ->whereNull('pmi.deleted_at');
            })
            ->exists();

        abort_unless($belongs, 404);
    }
}
