<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Identity\User;
use App\Services\Platform\EntitlementService;
use App\Services\Platform\TenantService;
use App\Services\Property\PropertyMapService;
use App\Services\Property\PropertyService;
use Illuminate\Http\Request;
use Inertia\Response;

class PropertyController extends Controller
{
    public function __construct(
        private readonly PropertyService    $propertyService,
        private readonly EntitlementService $entitlementService,
        private readonly TenantService      $tenantService,
    ) {}

    public function index(Request $request): Response
    {
        // SEC-038: type-validate filter inputs. SQL injection is already prevented
        // by parameterized queries — this rejects malformed/unexpected values
        // (non-numeric prices, bad state codes, unknown listing types) up front.
        // Empty-string filters (cleared UI inputs) are treated as absent.
        $request->merge(array_map(
            fn ($v) => $v === '' ? null : $v,
            $request->only(['state_code', 'county', 'listing_type', 'availability', 'min_price', 'max_price', 'min_acres', 'max_acres', 'min_hunters', 'max_hunters'])
        ));

        $request->validate([
            'state_code'   => ['nullable', 'string', 'size:2'],
            'county'       => ['nullable', 'string', 'max:100'],
            'listing_type' => ['nullable', 'in:annual_lease,seasonal_lease,day_hunt,auction'],
            'availability' => ['nullable', 'in:active,pending,leased,all'],
            'min_price'    => ['nullable', 'numeric', 'min:0'],
            'max_price'    => ['nullable', 'numeric', 'min:0'],
            'min_acres'    => ['nullable', 'numeric', 'min:0'],
            'max_acres'    => ['nullable', 'numeric', 'min:0'],
            'min_hunters'  => ['nullable', 'integer', 'min:0'],
            'max_hunters'  => ['nullable', 'integer', 'min:0'],
            'species'      => ['nullable', 'array'],
            'species.*'    => ['string', 'max:40'],
            'page'         => ['nullable', 'integer', 'min:1'],
        ]);

        $filters = $request->only(['state_code', 'county', 'listing_type', 'availability', 'min_price', 'max_price', 'min_acres', 'max_acres', 'min_hunters', 'max_hunters']);

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
            'status'           => $listing->status,
            'season_start'     => $listing->season_start,
            'season_end'       => $listing->season_end,
            'price_per_hunter' => $listing->price_per_hunter,
            'price_total'      => $listing->price_total,
            'max_hunters'      => $listing->max_hunters,
            'is_featured'      => (bool) $listing->is_featured,
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
            'config'   => $this->pageConfig(),
        ]);
    }

    /**
     * Admin-editable presentation settings for the listings page (hero copy,
     * filter visibility/labels, card layout, CTA buttons). Stored as
     * `properties.*` in DB 12 tenant_settings via PropertyListingSettings; every
     * default mirrors the page's original hardcoded values.
     */
    private function pageConfig(): array
    {
        $p = fn (string $k, string $d) => $this->tenantService->getSetting("properties.{$k}", $d);
        $bool = fn (string $k) => (bool) (int) $this->tenantService->getSetting("properties.{$k}", '1');

        return [
            'hero_eyebrow'        => $p('hero_eyebrow',        'Find Land'),
            'hero_headline'       => $p('hero_headline',       'Hunting Land for Lease'),
            'hero_subhead_suffix' => $p('hero_subhead_suffix', 'across the United States'),

            'cta_guest_label'   => $p('cta_guest_label',   'Join Now'),
            'cta_guest_url'     => $p('cta_guest_url',     '/get-started'),
            'cta_apply_label'   => $p('cta_apply_label',   'Apply'),
            'cta_details_label' => $p('cta_details_label', 'Details'),

            'filter_state_enabled'   => $bool('filter_state_enabled'),
            'filter_type_enabled'    => $bool('filter_type_enabled'),
            'filter_price_enabled'   => $bool('filter_price_enabled'),
            'filter_acres_enabled'   => $bool('filter_acres_enabled'),
            'filter_hunters_enabled' => $bool('filter_hunters_enabled'),
            'filter_species_enabled' => $bool('filter_species_enabled'),
            'filter_state_label'     => $p('filter_state_label',   'State'),
            'filter_type_label'      => $p('filter_type_label',    'Lease Type'),
            'filter_price_label'     => $p('filter_price_label',   'Price Range'),
            'filter_acres_label'     => $p('filter_acres_label',   'Acres'),
            'filter_hunters_label'   => $p('filter_hunters_label', 'Party Size'),
            'filter_species_label'   => $p('filter_species_label', 'Game Species'),

            'card_columns'          => (int) $p('card_columns', '2'),
            'card_show_acres'       => $bool('card_show_acres'),
            'card_show_species'     => $bool('card_show_species'),
            'card_show_price'       => $bool('card_show_price'),
            'card_show_max_hunters' => $bool('card_show_max_hunters'),
        ];
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

    public function show(string $slug): Response|\Illuminate\Http\RedirectResponse
    {
        $property = $this->propertyService->findBySlug($slug);

        if (! $property) {
            abort(404);
        }

        $property->load(['publicListings', 'photos', 'species', 'rules']);

        // A property stays publicly viewable while it has any listing that is
        // open (active), reserved (pending), or leased — a leased listing keeps
        // its page (badged "Leased Out") rather than 404'ing, so an indexed URL
        // never goes dead. Only when nothing public remains (drafts, expired, or
        // archived only) is the property pulled from the frontend.
        if ($property->publicListings->isEmpty()) {
            abort(404);
        }

        // Detail pages are members-only EXCEPT for featured (advertising)
        // listings, which guests may view. A guest hitting a non-featured
        // property's URL is redirected to sign-up.
        if (! auth()->check() && ! $property->publicListings->contains(fn ($l) => $l->is_featured)) {
            return redirect('/get-started');
        }

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
                'listings' => $property->publicListings->map(fn ($l) => [
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
