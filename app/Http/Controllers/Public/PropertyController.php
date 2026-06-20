<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Identity\User;
use App\Services\Platform\EntitlementService;
use App\Services\Property\PropertyMapService;
use App\Services\Property\PropertyService;
use Illuminate\Http\Request;
use Inertia\Response;

class PropertyController extends Controller
{
    public function __construct(
        private readonly PropertyService    $propertyService,
        private readonly EntitlementService $entitlementService,
    ) {}

    public function index(Request $request): Response
    {
        // SEC-038: type-validate filter inputs. SQL injection is already prevented
        // by parameterized queries — this rejects malformed/unexpected values
        // (non-numeric prices, bad state codes, unknown listing types) up front.
        // Empty-string filters (cleared UI inputs) are treated as absent.
        $request->merge(array_map(
            fn ($v) => $v === '' ? null : $v,
            $request->only(['state_code', 'county', 'listing_type', 'min_price', 'max_price'])
        ));

        $request->validate([
            'state_code'   => ['nullable', 'string', 'size:2'],
            'county'       => ['nullable', 'string', 'max:100'],
            'listing_type' => ['nullable', 'in:annual_lease,seasonal_lease,day_hunt,auction'],
            'min_price'    => ['nullable', 'numeric', 'min:0'],
            'max_price'    => ['nullable', 'numeric', 'min:0'],
            'species'      => ['nullable', 'array'],
            'species.*'    => ['string', 'max:40'],
            'page'         => ['nullable', 'integer', 'min:1'],
        ]);

        $filters = $request->only(['state_code', 'county', 'listing_type', 'min_price', 'max_price']);

        if ($request->has('species')) {
            $filters['species'] = (array) $request->input('species');
        }

        // Single-state-restricted (e.g. free-tier) hunters may only browse listings
        // in their locked home state; featured listings stay visible everywhere as
        // advertising. Unrestricted members and guests are unaffected (null = no gate).
        // Kept out of $filters so it is not echoed back as a user-facing filter.
        $paginator = $this->propertyService->searchListings(array_merge($filters, [
            'restricted_state' => $this->browseRestrictedState($request),
            'page'             => $request->integer('page', 1),
            'per_page'         => 20,
        ]));

        $listings = $paginator->through(fn ($listing) => [
            'id'               => $listing->id,
            'listing_type'     => $listing->listing_type,
            'season_start'     => $listing->season_start,
            'season_end'       => $listing->season_end,
            'price_per_hunter' => $listing->price_per_hunter,
            'price_total'      => $listing->price_total,
            'max_hunters'      => $listing->max_hunters,
            'property' => [
                'id'             => $listing->property->id,
                'title'          => $listing->property->title,
                'slug'           => $listing->property->slug,
                'state_code'     => $listing->property->state_code,
                'county'         => $listing->property->county,
                'total_acres'    => (float) $listing->property->total_acres,
                'huntable_acres' => $listing->property->huntable_acres ? (float) $listing->property->huntable_acres : null,
                'species'        => $listing->property->species->map(fn ($s) => $s->species_code)->values()->all(),
            ],
        ]);

        return inertia('Public/Properties', [
            'listings' => $listings,
            'filters'  => $filters,
        ]);
    }

    /**
     * The home state a logged-in member is locked to for browsing, or null when
     * unrestricted (unrestricted member, or guest with no session). Mirrors the
     * apply-time gate so the search list never shows out-of-state listings a free
     * hunter could not apply to anyway.
     */
    private function browseRestrictedState(Request $request): ?string
    {
        $userId = $request->session()->get('auth.user_id');
        if (! $userId) {
            return null;
        }

        $user = User::on('identity')->find($userId);

        return $user ? $this->entitlementService->restrictedHuntState($user) : null;
    }

    public function show(string $slug): Response
    {
        $property = $this->propertyService->findBySlug($slug);

        if (! $property) {
            abort(404);
        }

        $property->load(['activeListings', 'photos', 'species', 'rules']);

        // Base boundary image only — marker overlays are never exposed publicly
        $boundaryMap = app(PropertyMapService::class)->getBoundaryImage($property->id);

        return inertia('Public/PropertyDetail', [
            'property' => [
                'id'             => $property->id,
                'boundary_map_url' => $boundaryMap
                    ? route('property-maps.show', $boundaryMap->document_id)
                    : null,
                'boundary_map_coords' => (
                    $boundaryMap
                    && $boundaryMap->show_coords_publicly
                    && $boundaryMap->latitude !== null
                    && $boundaryMap->longitude !== null
                ) ? ['lat' => $boundaryMap->latitude, 'lng' => $boundaryMap->longitude] : null,
                'title'          => $property->title,
                'slug'           => $property->slug,
                'description'    => $property->description,
                'status'         => $property->status,
                'state_code'     => $property->state_code,
                'county'         => $property->county,
                'total_acres'    => $property->total_acres,
                'huntable_acres' => $property->huntable_acres,
                'photos'         => $property->photos
                    ->sortBy([['is_primary', 'desc'], ['sort_order', 'asc']])
                    ->values()
                    ->map(fn ($p) => [
                        'id'         => $p->id,
                        'url'        => route('property-photos.show', $p->document_id),
                        'caption'    => $p->caption,
                        'tags'       => $p->tags ?? [],
                        'is_primary' => (bool) $p->is_primary,
                        'sort_order' => $p->sort_order,
                    ])->values(),
                'species'        => $property->species->map(fn ($s) => [
                    'species_code' => $s->species_code,
                ])->values(),
                'rules'          => $property->rules->map(fn ($r) => [
                    'id'         => $r->id,
                    'rule_text'  => $r->rule_text,
                    'sort_order' => $r->sort_order,
                ])->values(),
                'active_listings' => $property->activeListings->map(fn ($l) => [
                    'id'              => $l->id,
                    'listing_type'    => $l->listing_type,
                    'status'          => $l->status,
                    'season_start'    => $l->season_start,
                    'season_end'      => $l->season_end,
                    'min_hunters'     => $l->min_hunters,
                    'max_hunters'     => $l->max_hunters,
                    'price_per_hunter' => $l->price_per_hunter,
                    'price_total'     => $l->price_total,
                    'deposit_amount'  => $l->deposit_amount,
                    'deposit_percent' => $l->deposit_percent,
                ])->values(),
            ],
        ]);
    }
}
