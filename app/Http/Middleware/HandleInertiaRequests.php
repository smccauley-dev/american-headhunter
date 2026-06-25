<?php

namespace App\Http\Middleware;

use App\Services\Platform\TenantService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'flash' => [
                'success' => $request->session()->get('success'),
                'error'   => $request->session()->get('error'),
            ],
            'auth' => [
                'authenticated' => (bool) $request->session()->get('auth.user_id'),
                'user_id'       => $request->session()->get('auth.user_id'),
            ],
            // Public site chrome (top bar + main nav), CMS-driven and shared with
            // every page so the marketing nav is universal. Lazy closure so it's
            // skipped on partial reloads that don't ask for it; TenantService is
            // Valkey-cached, so resolving it per full load is cheap.
            'nav' => fn (): array => $this->navData(),
        ]);
    }

    /**
     * The public navigation/top-bar settings from the platform CMS
     * (DB 12 tenant_settings, edited via the admin Navigation Settings page).
     *
     * @return array<string, mixed>
     */
    private function navData(): array
    {
        $t = app(TenantService::class);

        $logoPath = $t->getSetting('site.logo_path', null);
        $logoUrl  = ($logoPath && str_starts_with($logoPath, 'site/'))
            ? Storage::disk('public')->url($logoPath)
            : null;

        $defaultNavLinks = [
            ['label' => 'Find Land',    'href' => '/properties',   'enabled' => true],
            ['label' => 'Auctions',     'href' => '/auctions',     'enabled' => true],
            ['label' => 'Outfitters',   'href' => '/outfitters',   'enabled' => true],
            ['label' => 'Pricing',      'href' => '/pricing',      'enabled' => true],
            ['label' => 'How It Works', 'href' => '/how-it-works', 'enabled' => true],
        ];

        $navLinks = $t->getSetting('nav.links', $defaultNavLinks) ?? $defaultNavLinks;

        return [
            'logo_url' => $logoUrl,
            'topbar' => [
                'tagline' => $t->getSetting('topbar.tagline', 'Hunting Lease Marketplace'),
                'phone'   => $t->getSetting('topbar.phone',   '(800) 555-0124'),
                'link1'   => $t->getSetting('topbar.link1',   'Hunters'),
                'link2'   => $t->getSetting('topbar.link2',   'Landowners'),
                'link3'   => $t->getSetting('topbar.link3',   'Clubs'),
                'link4'   => $t->getSetting('topbar.link4',   'Outfitters'),
            ],
            'links'        => array_values(array_filter((array) $navLinks, fn ($l) => $l['enabled'] ?? true)),
            'cta_label'    => $t->getSetting('nav.cta_label',    'List Your Land →'),
            'cta_href'     => $t->getSetting('nav.cta_href',     '/get-started?type=landowner'),
            'signin_label' => $t->getSetting('nav.signin_label', 'Sign In'),
            'signin_href'  => $t->getSetting('nav.signin_href',  '/login'),
        ];
    }
}
