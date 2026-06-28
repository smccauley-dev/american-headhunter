<?php

namespace Tests\Feature\Property;

use App\Services\Property\PropertyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PropertyService::searchListings availability filter. The public search defaults
 * to on-market listings — active plus landowner-marked unavailable (still posted) —
 * but lets a browser narrow to pending (under contract), leased, or unavailable, or
 * widen to all publicly-visible states. Drafts, expired, and archived listings are
 * never returned regardless of the filter.
 *
 * Fixtures are committed (searchListings reads the property_read replica) and
 * removed in tearDown.
 */
class ListingAvailabilityFilterTest extends TestCase
{
    private string $activeId;
    private string $pendingId;
    private string $leasedId;
    private string $unavailableId;
    private string $draftId;
    private string $pausedId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->activeId      = $this->makeListing('active');
        $this->pendingId     = $this->makeListing('pending');
        $this->leasedId      = $this->makeListing('leased');
        $this->unavailableId = $this->makeListing('unavailable');
        $this->draftId       = $this->makeListing('draft');
        // On-market status, but paused (private) — must never surface publicly.
        $this->pausedId      = $this->makeListing('active', 'private');
    }

    protected function tearDown(): void
    {
        foreach ([$this->activeId, $this->pendingId, $this->leasedId, $this->unavailableId, $this->draftId, $this->pausedId] as $propertyId) {
            DB::connection('property')->table('property_listings')->where('property_id', $propertyId)->delete();
            DB::connection('property')->table('properties')->where('id', $propertyId)->delete();
        }

        foreach (['property', 'property_read'] as $conn) {
            try { DB::connection($conn)->disconnect(); } catch (\Throwable) {}
        }

        parent::tearDown();
    }

    /** Insert a property + listing in the given status/visibility; returns the property id. */
    private function makeListing(string $status, string $visibility = 'public'): string
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
            'visibility'       => $visibility,
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

    public function test_default_search_returns_active_and_unavailable_listings(): void
    {
        // The default browse keeps unavailable listings "out there" alongside the
        // active ones; only pending/leased/draft are held back.
        $ids = $this->searchPropertyIds([]);

        $this->assertContains($this->activeId, $ids);
        $this->assertContains($this->unavailableId, $ids);
        $this->assertNotContains($this->pendingId, $ids);
        $this->assertNotContains($this->leasedId, $ids);
        $this->assertNotContains($this->draftId, $ids);
    }

    public function test_unavailable_filter_returns_only_unavailable_listings(): void
    {
        $ids = $this->searchPropertyIds(['availability' => 'unavailable']);

        $this->assertContains($this->unavailableId, $ids);
        $this->assertNotContains($this->activeId, $ids);
        $this->assertNotContains($this->leasedId, $ids);
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
        $this->assertContains($this->unavailableId, $ids);
        $this->assertNotContains($this->draftId, $ids, 'Drafts are never public.');
        $this->assertNotContains($this->pausedId, $ids, 'Paused (private) listings are never public.');
    }

    public function test_paused_listings_are_excluded_from_every_filter(): void
    {
        foreach (['active', 'pending', 'leased', 'unavailable', 'all'] as $availability) {
            $ids = $this->searchPropertyIds(['availability' => $availability]);
            $this->assertNotContains(
                $this->pausedId,
                $ids,
                "A paused listing must not appear under the '{$availability}' filter.",
            );
        }
    }
}
