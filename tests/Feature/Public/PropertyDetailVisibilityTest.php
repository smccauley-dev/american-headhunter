<?php

namespace Tests\Feature\Public;

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Public property-detail visibility. A property is reachable at
 * /properties/{slug} only while it has at least one ACTIVE listing. Once every
 * listing is leased (sold_out), expired, or still a draft, the property is
 * pulled from the public frontend entirely — a direct URL must 404, not just
 * drop out of search — so a leased exclusive listing can't still look available.
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

    public function test_a_property_whose_only_listing_is_sold_out_is_not_viewable(): void
    {
        DB::connection('property')->table('property_listings')
            ->where('id', $this->listingId)->update(['status' => 'sold_out']);

        $this->get("/properties/{$this->slug}")->assertNotFound();
    }
}
