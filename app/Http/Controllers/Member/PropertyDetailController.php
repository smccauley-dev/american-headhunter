<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Models\Property\PropertyAmenity;
use App\Models\Property\PropertyContact;
use App\Models\Property\PropertyMapMarker;
use App\Services\Lease\CheckInService;
use App\Services\Property\PropertyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Landowner front-end property details (member portal) — the tabbed management
 * hub that mirrors the admin PropertyFormV2: Game Type, Property Rules, Amenities
 * (edited together), plus Photos, Map, Check In/Out, Team (managers) and Contacts.
 * Game-type/rules/amenities are saved here via update(); the other tabs post to
 * their own dedicated controllers. Every action is scoped through
 * PropertyService::userCanManageProperty (the properties table has no RLS policy).
 */
class PropertyDetailController extends Controller
{
    public function __construct(
        private readonly PropertyService $properties,
        private readonly CheckInService $checkIns,
    ) {}

    private const ROLES = [
        'owner'    => 'Owner',
        'co_owner' => 'Co-Owner',
        'manager'  => 'Manager',
        'operator' => 'Operator',
    ];

    public function edit(string $property): Response
    {
        $record = $this->authorizeManage($property);

        return Inertia::render('Member/Properties/Details', [
            'property' => [
                'id'          => $record->id,
                'title'       => $record->title,
                'status'      => $record->status,
                'state_code'  => $record->state_code,
                'county'      => $record->county,
                'total_acres' => $record->total_acres !== null ? (float) $record->total_acres : null,
            ],
            // Game type / rules / amenities (shared form)
            'species'        => $this->properties->getSpeciesFor($property),
            'rules'          => $this->properties->getRulesFor($property),
            'amenityIds'     => $this->properties->getAmenityIdsFor($property),
            'speciesOptions' => PropertyService::SPECIES_LABELS,
            'amenityCatalog' => $this->properties->getAmenityCatalog(),
            // Photos
            'photos'         => $this->properties->getPhotosForDisplay($property),
            // Map
            'mapImages'        => $this->properties->getMapImagesForDisplay($property),
            'deletedMapImages' => $this->properties->getDeletedMapImagesForDisplay($property),
            'markerTypes'      => PropertyMapMarker::TYPES,
            'markerColors'     => PropertyMapMarker::TYPE_COLORS,
            // Check In/Out
            'checkIns'       => $this->presentCheckIns($property),
            // Team (managers)
            'managers'       => $this->properties->getManagersForProperty($property),
            'roles'          => self::ROLES,
            // Contacts
            'contactDirectory' => $this->properties->getContactDirectory($property, includeManagerIds: true),
            'eligibleManagers' => $this->properties->getEligibleManagerContacts($property),
            'editableContacts' => $this->properties->getEditableContacts($property),
            'contactTypes'     => PropertyContact::TYPES,
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

        return back()->with('status', 'Property details saved.');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function presentCheckIns(string $propertyId): array
    {
        return array_map(fn (array $r) => [
            'name'           => $r['name'],
            'email'          => $r['email'],
            'lease_ref'      => $r['lease_ref'],
            'checked_in_at'  => $r['checked_in_at']?->format('M j, Y g:i A'),
            'checked_out_at' => $r['checked_out_at']?->format('M j, Y g:i A'),
            'open'           => $r['open'],
        ], $this->checkIns->getHistoryForProperty($propertyId));
    }

    /** Resolve a property the current user owns or actively manages, or 404. */
    private function authorizeManage(string $propertyId)
    {
        $userId = session('auth.user_id');
        abort_unless($this->properties->userCanManageProperty($userId, $propertyId), 404);

        return $this->properties->find($propertyId) ?? abort(404);
    }
}
