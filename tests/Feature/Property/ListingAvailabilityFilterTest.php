<?php

namespace Tests\Feature\Property;

use App\Services\Property\PropertyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PropertyService::searchListings availability filter. The public search defaults
 * to on-market (active) listings, but lets a browser narrow to pending (under
 * contract) or leased, or widen to all publicly-visible states. Drafts, expired,
 * and archived listings are never returned regardless of the filter.
 *
 * Fixtures are committed (searchListings reads the property_read replica) and
 * removed in tearDown.
 */
class ListingAvailabilityFilterTest extends TestCase
{
    private string $activeId;
    private string $pendingId;
    private string $leasedId;
    private string $draftId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->activeId  = $this->makeListing('active');
        $this->pendingId = $this->makeListing('pending');
        $this->leasedId  = $this->makeListing('leased');
        $this->draftId   = $this->makeListing('draft');
    }

    protected function tearDown(): void
    {
        foreach ([$this->activeId, $this->pendingId, $this->leasedId, $this->draftId] as $propertyId) {
            DB::connection('property')->table('property_listings')->where('property_id', $propertyId)->delete();
            DB::connection('property')->table('properties')->where('id', $propertyId)->delete();
        }

        foreach (['property', 'property_read'] as $conn) {
            try { DB::connection($conn)->disconnect(); } catch (\Throwable) {}
        }

        parent::tearDown();
    }

    /** Insert a public property + listing in the given status; returns the property id. */
    private function makeListing(string $status): string
    {
        $propertyId = (string) Str::uuid();

        DB::connection('property')->table('properties')->insert([
            'id'            => $propertyId,
            'owner_user_id' => (string) Str::uuid(),
            'title'         => "Avail Tract {$propertyId}",
            'slug'          => "avail-{$propertyId}",
            'status'        => 'active',
            'state_code'    => 'TX',
            'county'        => 'Test',
            'total_acres'   => '500.00',
        ]);

        DB::connection('property')->table('property_listings')->insert([
            'id'               => (string) Str::uuid(),
            'property_id'      => $propertyId,
            'listing_type'     => 'annual_lease',
            'status'           => $status,
            'season_start'     => '2026-10-01',
            'season_end'       => '2026-11-30',
            'max_hunters'      => 4,
            'price_per_hunter' => '500.00',
            'deposit_percent'  => 25,
            'auto_renew'       => false,
            'visibility'       => 'public',
            'is_featured'      => false,
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

    public function test_default_search_returns_only_active_listings(): void
    {
        $ids = $this->searchPropertyIds([]);

        $this->assertContains($this->activeId, $ids);
        $this->assertNotContains($this->pendingId, $ids);
        $this->assertNotContains($this->leasedId, $ids);
        $this->assertNotContains($this->draftId, $ids);
    }

    public function test_pending_filter_returns_only_pending_listings(): void
    {
        $ids = $this->searchPropertyIds(['availability' => 'pending']);

        $this->assertContains($this->pendingId, $ids);
        $this->assertNotContains($this->activeId, $ids);
        $this->assertNotContains($this->leasedId, $ids);
    }

    public function test_leased_filter_returns_only_leased_listings(): void
    {
        $ids = $this->searchPropertyIds(['availability' => 'leased']);

        $this->assertContains($this->leasedId, $ids);
        $this->assertNotContains($this->activeId, $ids);
        $this->assertNotContains($this->pendingId, $ids);
    }

    public function test_all_filter_returns_every_public_status_but_not_draft(): void
    {
        $ids = $this->searchPropertyIds(['availability' => 'all']);

        $this->assertContains($this->activeId, $ids);
        $this->assertContains($this->pendingId, $ids);
        $this->assertContains($this->leasedId, $ids);
        $this->assertNotContains($this->draftId, $ids, 'Drafts are never public.');
    }
}
