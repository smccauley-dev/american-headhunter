<?php

namespace Tests\Feature\Property;

use App\Services\Property\PropertyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PropertyService::searchListings home-state gate. A single-state-restricted
 * member (e.g. free tier) may only browse listings in their locked home state,
 * with featured listings staying visible everywhere as advertising. The gate is
 * opt-in via the restricted_state filter — absent it, every active public listing
 * is returned (unrestricted members and guests are unaffected).
 *
 * Fixtures are committed (searchListings reads the property_read replica) and
 * removed in tearDown.
 */
class BrowseHomeStateGateTest extends TestCase
{
    private string $inStateId;        // TX, not featured  — should show under TX gate
    private string $outStateId;       // CO, not featured  — should be HIDDEN under TX gate
    private string $outStateFeatured; // CO, featured      — should show under TX gate

    protected function setUp(): void
    {
        parent::setUp();

        $this->inStateId        = $this->makeListing('TX', false);
        $this->outStateId       = $this->makeListing('CO', false);
        $this->outStateFeatured = $this->makeListing('CO', true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->inStateId, $this->outStateId, $this->outStateFeatured] as $propertyId) {
            DB::connection('property')->table('property_listings')->where('property_id', $propertyId)->delete();
            DB::connection('property')->table('properties')->where('id', $propertyId)->delete();
        }

        foreach (['property', 'property_read'] as $conn) {
            try { DB::connection($conn)->disconnect(); } catch (\Throwable) {}
        }

        parent::tearDown();
    }

    /** Insert an active public property + listing, returning the property id. */
    private function makeListing(string $stateCode, bool $featured): string
    {
        $propertyId = (string) Str::uuid();

        DB::connection('property')->table('properties')->insert([
            'id'            => $propertyId,
            'owner_user_id' => (string) Str::uuid(),
            'title'         => "Gate Tract {$propertyId}",
            'slug'          => "gate-{$propertyId}",
            'status'        => 'active',
            'state_code'    => $stateCode,
            'county'        => 'Test',
            'total_acres'   => '500.00',
        ]);

        DB::connection('property')->table('property_listings')->insert([
            'id'               => (string) Str::uuid(),
            'property_id'      => $propertyId,
            'listing_type'     => 'annual_lease',
            'status'           => 'active',
            'season_start'     => '2026-10-01',
            'season_end'       => '2026-11-30',
            'max_hunters'      => 4,
            'price_per_hunter' => '500.00',
            'deposit_percent'  => 25,
            'auto_renew'       => false,
            'visibility'       => 'public',
            'is_featured'      => $featured,
        ]);

        return $propertyId;
    }

    private function searchPropertyIds(array $filters): array
    {
        $paginator = app(PropertyService::class)->searchListings($filters);

        return collect($paginator->items())
            ->map(fn ($listing) => $listing->property_id)
            ->all();
    }

    public function test_restricted_member_sees_home_state_and_featured_only(): void
    {
        $ids = $this->searchPropertyIds(['restricted_state' => 'TX']);

        $this->assertContains($this->inStateId, $ids, 'In-state listing must be visible.');
        $this->assertContains($this->outStateFeatured, $ids, 'Featured out-of-state listing stays visible.');
        $this->assertNotContains($this->outStateId, $ids, 'Non-featured out-of-state listing must be hidden.');
    }

    public function test_unrestricted_search_returns_all_states(): void
    {
        $ids = $this->searchPropertyIds([]);

        $this->assertContains($this->inStateId, $ids);
        $this->assertContains($this->outStateId, $ids);
        $this->assertContains($this->outStateFeatured, $ids);
    }
}
