<?php

namespace App\Services\Property;

use App\Models\Property\Property;
use App\Models\Property\PropertyMapImage;
use App\Models\Property\PropertyMapMarker;
use App\Services\BaseService;
use App\Services\Documents\DocumentService;
use App\Support\ExifGps;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PropertyMapService extends BaseService
{
    public function __construct(
        private readonly DocumentService $documentService,
    ) {}

    // ─── Reads ───────────────────────────────────────────────────────────────────

    /** Live map images for a property — boundary first, then by sort order. */
    public function getMapImages(string $propertyId): Collection
    {
        return PropertyMapImage::where('property_id', $propertyId)
            ->whereNull('deleted_at')
            ->orderByDesc('is_boundary')
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get();
    }

    /** The boundary map image, if one exists. */
    public function getBoundaryImage(string $propertyId): ?PropertyMapImage
    {
        return PropertyMapImage::where('property_id', $propertyId)
            ->whereNull('deleted_at')
            ->where('is_boundary', true)
            ->first();
    }

    /** Soft-deleted map images (for the admin recovery section). */
    public function getDeletedMapImages(string $propertyId): Collection
    {
        return PropertyMapImage::where('property_id', $propertyId)
            ->whereNotNull('deleted_at')
            ->orderByDesc('deleted_at')
            ->get();
    }

    // ─── Image writes ────────────────────────────────────────────────────────────

    /**
     * Store an uploaded map image via DocumentService and attach it to the
     * property. The first live map image automatically becomes the boundary map.
     */
    public function addMapImage(
        string $propertyId,
        UploadedFile $file,
        ?string $description = null,
    ): PropertyMapImage {
        $property = Property::findOrFail($propertyId);

        $document = $this->documentService->storeUploadedFile(
            $file,
            $property->owner_user_id,
            'photo',
        );

        [$latitude, $longitude] = ExifGps::extract($file);

        $isFirst  = ! PropertyMapImage::where('property_id', $propertyId)->whereNull('deleted_at')->exists();
        $nextSort = (int) PropertyMapImage::where('property_id', $propertyId)
            ->whereNull('deleted_at')
            ->max('sort_order') + 1;

        return PropertyMapImage::create([
            'property_id' => $propertyId,
            'document_id' => $document->id,
            'sort_order'  => $isFirst ? 0 : $nextSort,
            'description' => $description,
            'latitude'    => $latitude,
            'longitude'   => $longitude,
            'is_boundary' => $isFirst,
        ]);
    }

    public function updateMapImageDetails(
        string $mapImageId,
        ?string $description,
        ?float $latitude = null,
        ?float $longitude = null,
        bool $showCoordsPublicly = false,
    ): void {
        if ($latitude !== null && ($latitude < -90 || $latitude > 90)) {
            throw new \InvalidArgumentException('Latitude must be between -90 and 90.');
        }
        if ($longitude !== null && ($longitude < -180 || $longitude > 180)) {
            throw new \InvalidArgumentException('Longitude must be between -180 and 180.');
        }

        PropertyMapImage::whereNull('deleted_at')->findOrFail($mapImageId)->update([
            'description'          => $description !== '' ? $description : null,
            'latitude'             => $latitude,
            'longitude'            => $longitude,
            'show_coords_publicly' => $showCoordsPublicly,
        ]);
    }

    /** Make this image the property's boundary map. Exactly one per property. */
    public function setBoundaryImage(string $mapImageId): void
    {
        $image = PropertyMapImage::whereNull('deleted_at')->findOrFail($mapImageId);

        DB::connection('property')->transaction(function () use ($image): void {
            PropertyMapImage::where('property_id', $image->property_id)
                ->where('id', '!=', $image->id)
                ->update(['is_boundary' => false]);

            $image->update(['is_boundary' => true]);
        });
    }

    /**
     * Soft-delete a map image (the underlying document stays live, mirroring
     * lease documents, so restore is lossless). Markers stay attached and
     * come back with the image. If the boundary map is deleted, the next
     * image is promoted.
     */
    public function deleteMapImage(string $mapImageId): void
    {
        $image = PropertyMapImage::whereNull('deleted_at')->findOrFail($mapImageId);

        $image->update(['deleted_at' => now()]);

        if ($image->is_boundary) {
            $image->update(['is_boundary' => false]);

            PropertyMapImage::where('property_id', $image->property_id)
                ->whereNull('deleted_at')
                ->orderBy('sort_order')
                ->orderBy('created_at')
                ->first()
                ?->update(['is_boundary' => true]);
        }
    }

    /**
     * Restore a soft-deleted map image. Becomes the boundary map again only
     * when the property currently has none.
     */
    public function restoreMapImage(string $mapImageId): void
    {
        $image = PropertyMapImage::whereNotNull('deleted_at')->findOrFail($mapImageId);

        $image->update(['deleted_at' => null]);

        $hasBoundary = PropertyMapImage::where('property_id', $image->property_id)
            ->whereNull('deleted_at')
            ->where('is_boundary', true)
            ->exists();

        if (! $hasBoundary) {
            $image->update(['is_boundary' => true]);
        }
    }

    // ─── Markers ─────────────────────────────────────────────────────────────────

    public function addMarker(
        string $mapImageId,
        string $label,
        string $markerType,
        float $xPercent,
        float $yPercent,
        ?float $latitude = null,
        ?float $longitude = null,
        ?string $notes = null,
        ?string $color = null,
    ): PropertyMapMarker {
        $this->assertMarkerInput($markerType, $xPercent, $yPercent);
        $color = $this->normalizeColor($color, $markerType);

        PropertyMapImage::whereNull('deleted_at')->findOrFail($mapImageId);

        return PropertyMapMarker::create([
            'map_image_id' => $mapImageId,
            'label'        => $label,
            'marker_type'  => $markerType,
            'x_percent'    => round($xPercent, 3),
            'y_percent'    => round($yPercent, 3),
            'latitude'     => $latitude,
            'longitude'    => $longitude,
            'color'        => $color,
            'notes'        => $notes !== '' ? $notes : null,
        ]);
    }

    public function updateMarker(
        string $markerId,
        string $label,
        string $markerType,
        ?float $latitude = null,
        ?float $longitude = null,
        ?string $notes = null,
        ?string $color = null,
    ): void {
        $this->assertMarkerInput($markerType, 0, 0);

        PropertyMapMarker::whereNull('deleted_at')->findOrFail($markerId)->update([
            'label'       => $label,
            'marker_type' => $markerType,
            'latitude'    => $latitude,
            'longitude'   => $longitude,
            'color'       => $this->normalizeColor($color, $markerType),
            'notes'       => $notes !== '' ? $notes : null,
        ]);
    }

    /**
     * Validate a hex color override; a value matching the type default is
     * stored as NULL so the marker follows the default if it ever changes.
     */
    private function normalizeColor(?string $color, string $markerType): ?string
    {
        if ($color === null || $color === '') {
            return null;
        }

        $color = strtolower($color);

        if (! preg_match('/^#[0-9a-f]{6}$/', $color)) {
            throw new \InvalidArgumentException("Invalid marker color '{$color}'. Use a 6-digit hex value like #1d4ed8.");
        }

        return $color === strtolower(PropertyMapMarker::TYPE_COLORS[$markerType] ?? '')
            ? null
            : $color;
    }

    /** Reposition a marker on its image (percent coordinates, drag-to-move). */
    public function moveMarker(string $markerId, float $xPercent, float $yPercent): void
    {
        if ($xPercent < 0 || $xPercent > 100 || $yPercent < 0 || $yPercent > 100) {
            throw new \InvalidArgumentException('Marker position must be within the image (0-100%).');
        }

        PropertyMapMarker::whereNull('deleted_at')->findOrFail($markerId)->update([
            'x_percent' => round($xPercent, 3),
            'y_percent' => round($yPercent, 3),
        ]);
    }

    public function deleteMarker(string $markerId): void
    {
        PropertyMapMarker::whereNull('deleted_at')->findOrFail($markerId)
            ->update(['deleted_at' => now()]);
    }

    private function assertMarkerInput(string $markerType, float $xPercent, float $yPercent): void
    {
        if (! array_key_exists($markerType, PropertyMapMarker::TYPES)) {
            throw new \InvalidArgumentException("Invalid marker type '{$markerType}'.");
        }
        if ($xPercent < 0 || $xPercent > 100 || $yPercent < 0 || $yPercent > 100) {
            throw new \InvalidArgumentException('Marker position must be within the image (0-100%).');
        }
    }
}
