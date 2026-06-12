<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Services\Property\PropertyService;
use Illuminate\Http\Request;
use Inertia\Response;

class PropertyController extends Controller
{
    public function __construct(private readonly PropertyService $propertyService) {}

    public function index(Request $request): Response
    {
        $filters = $request->only(['state_code', 'county', 'listing_type', 'min_price', 'max_price']);

        if ($request->has('species')) {
            $filters['species'] = (array) $request->input('species');
        }

        $paginator = $this->propertyService->searchListings(array_merge($filters, [
            'page'     => $request->integer('page', 1),
            'per_page' => 20,
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

    public function show(string $slug): Response
    {
        $property = $this->propertyService->findBySlug($slug);

        if (! $property) {
            abort(404);
        }

        $property->load(['activeListings', 'photos', 'species', 'rules']);

        return inertia('Public/PropertyDetail', [
            'property' => [
                'id'             => $property->id,
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
