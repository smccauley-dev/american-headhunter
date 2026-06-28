<?php

namespace Tests\Feature\Public;

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Public property-detail visibility. A property is reachable at
 * /properties/{slug} while it has at least one PUBLIC listing — active,
 * pending (under contract), or leased. A leased listing keeps its page (badged
 * "Leased Out") so an indexed URL never 404s and SEO equity is preserved. Only
 * when every listing is a draft, expired, or archived is the property pulled
 * from the public frontend entirely — then a direct URL must 404.
 *
 * Postgres-only fixtures via owner connections (testing runs as the owner role).
 */
class PropertyDetailVisibilityTest extends TestCase
{
    private string $propertyId;
    private string $listingId;
    private string $slug;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        $this->propertyId = (string) Str::uuid();
        $this->listingId  = (string) Str::uuid();
        $this->slug       = 'vis-ranch-' . Str::lower(Str::random(8));

        DB::connection('property')->table('properties')->insert([
            'id'            => $this->propertyId,
            'owner_user_id' => (string) Str::uuid(),
            'title'         => 'Visibility Test Ranch',
            'slug'          => $this->slug,
            'status'        => 'active',
            'state_code'    => 'TX',
            'county'        => 'Kerr',
            'total_acres'   => '500.00',
        ]);

        // Featured so a guest may view it (non-featured detail pages redirect
        // guests to signup) — keeps the test free of session auth.
        DB::connection('property')->table('property_listings')->insert([
            'id'           => $this->listingId,
            'property_id'  => $this->propertyId,
            'listing_type' => 'annual_lease',
            'status'       => 'active',
            'visibility'   => 'public',
            'is_featured'  => true,
            'price_total'  => '5000.00',
        ]);
    }

    protected function tearDown(): void
    {
        DB::connection('property')->table('property_listings')->where('id', $this->listingId)->delete();
        DB::connection('property')->table('properties')->where('id', $this->propertyId)->delete();

        parent::tearDown();
    }

    public function test_a_property_with_an_active_listing_is_viewable(): void
    {
        $this->get("/properties/{$this->slug}")->assertOk();
    }

    public function test_a_property_whose_only_listing_is_leased_is_still_viewable(): void
    {
        DB::connection('property')->table('property_listings')
            ->where('id', $this->listingId)->update(['status' => 'leased']);

        // SEO: a leased listing keeps its page (200) — it renders "Leased Out"
        // rather than going dead — so the indexed URL never 404s.
        $this->get("/properties/{$this->slug}")->assertOk();
    }

    public function test_a_property_whose_only_listing_is_pending_is_still_viewable(): void
    {
        DB::connection('property')->table('property_listings')
            ->where('id', $this->listingId)->update(['status' => 'pending']);

        $this->get("/properties/{$this->slug}")->assertOk();
    }

    public function test_a_property_with_no_public_listing_is_not_viewable(): void
    {
        // Draft/expired/archived are not public — nothing remains to show.
        DB::connection('property')->table('property_listings')
            ->where('id', $this->listingId)->update(['status' => 'archived']);

        $this->get("/properties/{$this->slug}")->assertNotFound();
    }
}
