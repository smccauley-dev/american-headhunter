<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Models\Property\PropertyAmenity;
use App\Services\Property\PropertyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Landowner front-end property details (member portal), Slice 4 — the Game Type
 * (species), Property Rules, and Amenities tabs from the admin PropertyFormV2,
 * edited together on one page. Scoped through PropertyService::userCanManageProperty
 * (the properties table has no RLS policy). Submitted amenity ids are filtered
 * against the live catalogue so a forged id is silently dropped.
 */
class PropertyDetailController extends Controller
{
    public function __construct(private readonly PropertyService $properties) {}

    public function edit(string $property): Response
    {
        $record = $this->authorizeManage($property);

        return Inertia::render('Member/Properties/Details', [
            'property' => [
                'id'    => $record->id,
                'title' => $record->title,
            ],
            'species'        => $this->properties->getSpeciesFor($property),
            'rules'          => $this->properties->getRulesFor($property),
            'amenityIds'     => $this->properties->getAmenityIdsFor($property),
            'speciesOptions' => PropertyService::SPECIES_LABELS,
            'amenityCatalog' => $this->properties->getAmenityCatalog(),
        ]);
    }

    public function update(Request $request, string $property): RedirectResponse
    {
        $this->authorizeManage($property);

        $data = $request->validate([
            'species'                => 'array',
            'species.*.species_code' => ['required', Rule::in(array_keys(PropertyService::SPECIES_LABELS))],
            'species.*.is_primary'   => 'boolean',
            'rules'                  => 'array',
            'rules.*.rule_text'      => 'required|string|max:500',
            'amenity_ids'            => 'array',
            'amenity_ids.*'          => 'string',
        ]);

        // Drop any amenity id that isn't a real catalogue entry.
        $amenityIds = PropertyAmenity::on('property_read')
            ->whereIn('id', $data['amenity_ids'] ?? [])
            ->pluck('id')
            ->all();

        $this->properties->saveDetails(
            $property,
            $data['species'] ?? [],
            $data['rules'] ?? [],
            $amenityIds,
        );

        return redirect()
            ->route('member.properties.details.edit', $property)
            ->with('status', 'Property details saved.');
    }

    /** Resolve a property the current user owns or actively manages, or 404. */
    private function authorizeManage(string $propertyId)
    {
        $userId = session('auth.user_id');
        abort_unless($this->properties->userCanManageProperty($userId, $propertyId), 404);

        return $this->properties->find($propertyId) ?? abort(404);
    }
}
