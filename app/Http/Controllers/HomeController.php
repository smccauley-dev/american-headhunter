<?php

namespace App\Http\Controllers;

use App\Services\Platform\TenantService;
use App\Services\Property\PropertyService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Response;

class HomeController extends Controller
{
    public function __construct(
        private readonly PropertyService $propertyService,
        private readonly TenantService $tenantService,
    ) {}

    public function __invoke(): Response
    {
        $t = $this->tenantService;

        $cardCount = (int) $t->getSetting('home.hero_card_count', '1');

        try {
            $page     = $this->propertyService->searchListings(['per_page' => max(6, $cardCount + 5)]);
            $listings = collect($page->items())->map(function ($listing) {
                $docId = $listing->property?->primary_photo_document_id;
                $listing->property?->setAttribute(
                    'primary_photo_url',
                    $docId ? route('property-photos.show', $docId) : null,
                );
                return $listing;
            })->all();
        } catch (\Throwable $e) {
            Log::error('HomeController: failed to load listings', ['error' => $e->getMessage()]);
            $listings = [];
        }

        $home = fn(string $k, mixed $d) => $t->getSetting("home.{$k}", $d);

        $logoPath = $t->getSetting('site.logo_path', null);
        $logoUrl  = ($logoPath && str_starts_with($logoPath, 'site/'))
            ? Storage::disk('public')->url($logoPath)
            : null;

        $defaultNavLinks = [
            ['label' => 'Find Land',    'href' => '/properties',            'enabled' => true],
            ['label' => 'Auctions',     'href' => '/auctions',               'enabled' => true],
            ['label' => 'Outfitters',   'href' => '/outfitters',             'enabled' => true],
            ['label' => 'How It Works', 'href' => '/how-it-works',           'enabled' => true],
        ];

        $navLinks = $t->getSetting('nav.links', $defaultNavLinks) ?? $defaultNavLinks;

        return inertia('Home', [
            'listings'     => $listings,
            'homeSettings' => [
                'site' => [
                    'logo_url' => $logoUrl,
                ],
                'topbar' => [
                    'tagline' => $t->getSetting('topbar.tagline', 'Hunting Lease Marketplace'),
                    'phone'   => $t->getSetting('topbar.phone',   '(800) 555-0124'),
                    'link1'   => $t->getSetting('topbar.link1',   'Hunters'),
                    'link2'   => $t->getSetting('topbar.link2',   'Landowners'),
                    'link3'   => $t->getSetting('topbar.link3',   'Clubs'),
                    'link4'   => $t->getSetting('topbar.link4',   'Outfitters'),
                ],
                'nav' => [
                    'links'        => array_values(array_filter((array) $navLinks, fn($l) => $l['enabled'] ?? true)),
                    'cta_label'    => $t->getSetting('nav.cta_label',    'List Your Land →'),
                    'cta_href'     => $t->getSetting('nav.cta_href',     '/get-started?type=landowner'),
                    'signin_label' => $t->getSetting('nav.signin_label', 'Sign In'),
                    'signin_href'  => $t->getSetting('nav.signin_href',  '/login'),
                ],
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
