<?php

namespace Tests\Feature\Member;

use App\Http\Controllers\Member\LeaseApplicationController;
use App\Models\Lease\LeaseApplication;
use App\Models\Property\PropertyListing;
use App\Services\Property\PropertyService;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The approve-&-create-lease modal pre-fills its Total Lease Price from the
 * listing's asking price: flat price_total, else price_per_hunter × desired
 * hunters, else blank. Pure logic — PropertyService::findListing is mocked, no DB.
 */
class ApproveLeasePriceDefaultTest extends TestCase
{
    private function askingPrice(?PropertyListing $listing, ?int $hunters): ?float
    {
        $props = Mockery::mock(PropertyService::class);
        $props->shouldReceive('findListing')->andReturn($listing);

        $controller = new LeaseApplicationController(
            $props,
            app(\App\Services\Lease\ApplicationService::class),
            app(\App\Services\Lease\ApplicationMessageService::class),
            app(\App\Services\Lease\EsignatureService::class),
            app(\App\Services\Lease\LeaseDocumentService::class),
            app(\App\Services\Billing\LeaseFinanceSummaryService::class),
        );

        $app = new LeaseApplication(['listing_id' => 'listing-uuid', 'desired_hunters' => $hunters]);

        $method = new ReflectionMethod($controller, 'listingAskingPrice');
        $method->setAccessible(true);

        return $method->invoke($controller, $app);
    }

    public function test_flat_price_total_is_used_directly(): void
    {
        $listing = new PropertyListing(['price_total' => 3200.00, 'price_per_hunter' => 800.00]);

        $this->assertSame(3200.0, $this->askingPrice($listing, 3));
    }

    public function test_per_hunter_price_multiplies_by_desired_hunters(): void
    {
        $listing = new PropertyListing(['price_total' => null, 'price_per_hunter' => 750.00]);

        $this->assertSame(2250.0, $this->askingPrice($listing, 3));
    }

    public function test_blank_when_listing_is_unresolvable(): void
    {
        $this->assertNull($this->askingPrice(null, 2));
    }

    public function test_blank_when_listing_has_no_usable_price(): void
    {
        $listing = new PropertyListing(['price_total' => null, 'price_per_hunter' => null]);

        $this->assertNull($this->askingPrice($listing, 2));
    }

    public function test_blank_for_per_hunter_pricing_without_a_hunter_count(): void
    {
        $listing = new PropertyListing(['price_total' => null, 'price_per_hunter' => 750.00]);

        $this->assertNull($this->askingPrice($listing, 0));
    }
}
