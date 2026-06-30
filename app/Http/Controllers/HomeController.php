<?php

namespace App\Http\Controllers;

use App\Services\Analytics\AnalyticsService;
use App\Services\Platform\TenantService;
use App\Services\Property\PropertyService;
use Illuminate\Support\Facades\Log;
use Inertia\Response;

class HomeController extends Controller
{
    public function __construct(
        private readonly PropertyService $propertyService,
        private readonly TenantService $tenantService,
        private readonly AnalyticsService $analyticsService,
    ) {}

    public function __invoke(): Response
    {
        $t = $this->tenantService;

        $cardCount = (int) $t->getSetting('home.hero_card_count', '1');

        try {
            // Curate the public payload (SEC-060). featuredListings() returns raw
            // Eloquent models; returning them directly serializes every non-hidden
            // attribute (owner_user_id, precise center_lat/center_lng,
            // boundary_geospatial_id, internal listing fields) into the page JSON.
            // Emit an explicit shape — mirroring Public\PropertyController::index —
            // that exposes only what the homepage card renders. Coordinates are
            // coarsened to the 2-decimal precision the hero card already displays,
            // so the precise location never leaves the server.
            $listings = $this->propertyService->featuredListings(max(6, $cardCount + 5))->map(function ($listing) {
                $property = $listing->property;
                $docId    = $property?->primary_photo_document_id;

                return [
                    'id'               => $listing->id,
                    'listing_type'     => $listing->listing_type,
                    'price_per_hunter' => $listing->price_per_hunter,
                    'price_total'      => $listing->price_total,
                    'min_hunters'      => $listing->min_hunters,
                    'max_hunters'      => $listing->max_hunters,
                    'season_start'     => $listing->season_start,
                    'season_end'       => $listing->season_end,
                    'property'         => $property ? [
                        'id'                => $property->id,
                        'title'             => $property->title,
                        'slug'              => $property->slug,
                        'state_code'        => $property->state_code,
                        'county'            => $property->county,
                        'total_acres'       => $property->total_acres,
                        'description'       => $property->description,
                        // Coarsened to ~1km (matches the hero card's .toFixed(2) display).
                        'center_lat'        => $property->center_lat !== null ? round((float) $property->center_lat, 2) : null,
                        'center_lng'        => $property->center_lng !== null ? round((float) $property->center_lng, 2) : null,
                        'primary_photo_url' => $docId ? route('property-photos.show', $docId) : null,
                        'species'           => $property->species->map(fn ($s) => ['species_code' => $s->species_code])->values()->all(),
                    ] : null,
                ];
            })->all();
        } catch (\Throwable $e) {
            Log::error('HomeController: failed to load listings', ['error' => $e->getMessage()]);
            $listings = [];
        }

        $home = fn(string $k, mixed $d) => $t->getSetting("home.{$k}", $d);

        // Live, public-safe platform counters from the DB 8 rollup (counts/acres
        // only — never revenue). Falls back to zeros before the first ETL run.
        try {
            $publicStats = $this->analyticsService->publicStats();
        } catch (\Throwable $e) {
            Log::error('HomeController: failed to load public stats', ['error' => $e->getMessage()]);
            $publicStats = ['total_users' => 0, 'total_leases' => 0, 'total_acres' => 0];
        }

        // Nav / top-bar / logo are now provided site-wide via the `nav` Inertia
        // shared prop (HandleInertiaRequests), so they are no longer assembled here.

        return inertia('Home', [
            'listings'     => $listings,
            'publicStats'  => $publicStats,
            'homeSettings' => [
                'hero' => [
                    'card_count'  => $cardCount,
                    'eyebrow'     => $home('hero_eyebrow',    'The Premier Hunting Lease Marketplace'),
                    'line1'       => $home('hero_line1',       'Land'),
                    'line2'       => $home('hero_line2',       'worth &'),
                    'line3'       => $home('hero_line3',       'hunting.'),
                    'stat1_label' => $home('hero_stat1_label', 'Properties Listed'),
                    'stat1_value' => $home('hero_stat1_value', '12,400+'),
                    'stat2_label' => $home('hero_stat2_label', 'States Covered'),
                    'stat2_value' => $home('hero_stat2_value', '48'),
                    'stat3_label' => $home('hero_stat3_label', 'Leases Signed'),
                    'stat3_value' => $home('hero_stat3_value', '38,000+'),
                ],
                'stats' => [
                    ['label' => $home('stat1_label', 'Active Properties'),  'num' => $home('stat1_num', '12,400+'), 'sub' => $home('stat1_sub', 'Across 48 states')],
                    ['label' => $home('stat2_label', 'Total Acres Listed'), 'num' => $home('stat2_num', '4.2M'),    'sub' => $home('stat2_sub', 'And growing every week')],
                    ['label' => $home('stat3_label', 'Leases Completed'),   'num' => $home('stat3_num', '38,000+'), 'sub' => $home('stat3_sub', 'Every one e-signed')],
                    ['label' => $home('stat4_label', 'Landowner Payouts'),  'num' => $home('stat4_num', '$47M'),    'sub' => $home('stat4_sub', 'Paid out to date')],
                ],
                'cta' => [
                    'headline' => $home('cta_headline', 'Your next season starts here.'),
                    'sub'      => $home('cta_sub',      "Join thousands of landowners and hunters who've moved the entire leasing process — from search to signature — into one platform."),
                ],
                'sections' => [
                    'almanac'      => (bool)(int) $home('section_almanac_enabled',      '1'),
                    'stats'        => (bool)(int) $home('section_stats_enabled',         '1'),
                    'expedition'   => (bool)(int) $home('section_expedition_enabled',    '1'),
                    'testimonials' => (bool)(int) $home('section_testimonials_enabled',  '1'),
                    'cta'          => (bool)(int) $home('section_cta_enabled',           '1'),
                ],
            ],
        ]);
    }
}
