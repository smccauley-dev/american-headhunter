<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Models\Property\PropertyListing;
use App\Services\Property\PropertyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Landowner front-end listing management (member portal), Slice 3. Every action
 * is scoped twice: the user must be able to manage the parent property
 * (PropertyService::userCanManageProperty — the `properties`/`property_listings`
 * tables have no RLS policy), and the listing must belong to that property
 * (findListingForProperty), so a listing id from another property 404s.
 */
class PropertyListingController extends Controller
{
    public function __construct(private readonly PropertyService $properties) {}

    private const LISTING_TYPES = [
        'annual_lease'   => 'Annual Lease',
        'seasonal_lease' => 'Seasonal Lease',
        'day_hunt'       => 'Day Hunt',
        'auction'        => 'Auction',
    ];

    private const STATUSES = [
        'draft'    => 'Draft',
        'active'   => 'Active',
        'sold_out' => 'Sold Out',
        'expired'  => 'Expired',
        'archived' => 'Archived',
    ];

    private const VISIBILITIES = [
        'public'       => 'Public',
        'members_only' => 'Members Only',
        'invite_only'  => 'Invite Only',
    ];

    public function index(string $property): Response
    {
        $record = $this->authorizeManage($property);

        $listings = $this->properties->getListingsForProperty($property)
            ->map(fn (PropertyListing $l) => $this->present($l))
            ->values()
            ->all();

        return Inertia::render('Member/Properties/Listings', [
            'property' => [
                'id'    => $record->id,
                'title' => $record->title,
            ],
            'listings'     => $listings,
            'listingTypes' => self::LISTING_TYPES,
            'statuses'     => self::STATUSES,
            'visibilities' => self::VISIBILITIES,
        ]);
    }

    public function store(Request $request, string $property): RedirectResponse
    {
        $this->authorizeManage($property);

        $this->properties->createListing($property, $this->validated($request));

        return redirect()
            ->route('member.properties.listings.index', $property)
            ->with('status', 'Listing created.');
    }

    public function update(Request $request, string $property, string $listing): RedirectResponse
    {
        $this->authorizeManage($property);
        $this->authorizeListing($property, $listing);

        $this->properties->updateListing($listing, $this->validated($request));

        return redirect()
            ->route('member.properties.listings.index', $property)
            ->with('status', 'Listing saved.');
    }

    public function destroy(string $property, string $listing): RedirectResponse
    {
        $this->authorizeManage($property);
        $this->authorizeListing($property, $listing);

        $this->properties->deleteListing($listing);

        return redirect()
            ->route('member.properties.listings.index', $property)
            ->with('status', 'Listing removed.');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function present(PropertyListing $l): array
    {
        return [
            'id'               => $l->id,
            'listing_type'     => $l->listing_type,
            'status'           => $l->status,
            'visibility'       => $l->visibility,
            'auto_renew'       => (bool) $l->auto_renew,
            'season_start'     => $l->season_start?->format('Y-m-d'),
            'season_end'       => $l->season_end?->format('Y-m-d'),
            'min_hunters'      => $l->min_hunters,
            'max_hunters'      => $l->max_hunters,
            'price_per_hunter' => $l->price_per_hunter !== null ? (float) $l->price_per_hunter : null,
            'price_total'      => $l->price_total !== null ? (float) $l->price_total : null,
            'deposit_amount'   => $l->deposit_amount !== null ? (float) $l->deposit_amount : null,
            'deposit_percent'  => $l->deposit_percent,
        ];
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'listing_type'     => ['required', Rule::in(array_keys(self::LISTING_TYPES))],
            'status'           => ['required', Rule::in(array_keys(self::STATUSES))],
            'visibility'       => ['required', Rule::in(array_keys(self::VISIBILITIES))],
            'auto_renew'       => 'boolean',
            'season_start'     => 'nullable|date',
            'season_end'       => 'nullable|date|after_or_equal:season_start',
            'max_hunters'      => 'required|integer|min:1',
            'min_hunters'      => 'nullable|integer|min:1|lte:max_hunters',
            'price_per_hunter' => 'nullable|numeric|min:0',
            'price_total'      => 'nullable|numeric|min:0',
            'deposit_amount'   => 'nullable|numeric|min:0',
            'deposit_percent'  => 'nullable|integer|between:0,100',
        ]);
    }

    /** Resolve a property the current user owns or actively manages, or 404. */
    private function authorizeManage(string $propertyId)
    {
        $userId = session('auth.user_id');
        abort_unless($this->properties->userCanManageProperty($userId, $propertyId), 404);

        return $this->properties->find($propertyId) ?? abort(404);
    }

    /** Confirm the listing belongs to the property, or 404. */
    private function authorizeListing(string $propertyId, string $listingId): void
    {
        abort_unless(
            $this->properties->findListingForProperty($propertyId, $listingId) !== null,
            404,
        );
    }
}
