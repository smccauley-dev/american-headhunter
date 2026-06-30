<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Property\PropertyDetailResource;
use App\Services\Property\GeospatialService;
use App\Services\Property\PropertyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PropertyController extends Controller
{
    public function __construct(
        private readonly PropertyService $propertyService,
        private readonly GeospatialService $geospatialService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only([
            'state_code', 'county', 'listing_type', 'species',
            'min_acres', 'max_acres', 'min_price', 'max_price',
            'page', 'per_page',
        ]);

        $results = $this->propertyService->searchListings($filters);

        return response()->json($results);
    }

    public function show(string $id): JsonResponse
    {
        $property = $this->propertyService->find($id);

        // Enforce public visibility — draft/inactive properties must not be
        // accessible via the public API regardless of whether the UUID is known.
        if (! $property || $property->status !== 'active' || $property->deleted_at !== null) {
            return response()->json(['error' => 'Not found'], 404);
        }

        // find() eager-loads: photos, species, rules (via property_read)
        // loadMissing adds the remaining two relations needed by PropertyDetailResource
        $property->loadMissing(['activeListings', 'amenities']);

        return response()->json(new PropertyDetailResource($property));
    }

    public function boundary(string $id): JsonResponse
    {
        // Enforce the same public-visibility gate as show() (SEC-059). The
        // boundary query keys only on property_id, so without this check it
        // would serve precise parcel geometry for draft/inactive/deleted
        // properties — the geometry sibling SEC-002 missed.
        $property = $this->propertyService->find($id);

        if (! $property || $property->status !== 'active' || $property->deleted_at !== null) {
            return response()->json(['error' => 'Not found'], 404);
        }

        // $id is the property UUID — GeospatialService queries by property_id
        $geoJson = $this->geospatialService->getPropertyBoundaryGeoJson($id);

        if (! $geoJson) {
            return response()->json(['error' => 'No boundary available'], 404);
        }

        return response()->json($geoJson);
    }
}
