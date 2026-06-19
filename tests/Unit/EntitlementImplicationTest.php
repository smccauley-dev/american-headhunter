<?php

namespace Tests\Unit;

use App\Services\Platform\EntitlementService;
use App\Support\Entitlements;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Entitlement implication resolution (Entitlements::IMPLIES). expandImplied is a
 * pure transformation over a key list, so it is exercised directly via reflection
 * — no DB or user fixtures required.
 */
class EntitlementImplicationTest extends TestCase
{
    private function expand(array $keys): array
    {
        $method = new ReflectionMethod(EntitlementService::class, 'expandImplied');
        $result = $method->invoke(app(EntitlementService::class), $keys);
        sort($result);

        return $result;
    }

    public function test_shared_trail_cams_implies_trail_camera_integration(): void
    {
        $result = $this->expand([Entitlements::SHARED_TRAIL_CAMS]);

        $this->assertContains(Entitlements::TRAIL_CAMERA_INTEGRATION, $result);
        $this->assertContains(Entitlements::SHARED_TRAIL_CAMS, $result);
    }

    public function test_a_key_with_no_implications_is_returned_unchanged(): void
    {
        $this->assertSame(
            [Entitlements::DIGITAL_ID_CARD],
            $this->expand([Entitlements::DIGITAL_ID_CARD]),
        );
    }

    public function test_already_present_implied_key_is_not_duplicated(): void
    {
        $result = $this->expand([
            Entitlements::SHARED_TRAIL_CAMS,
            Entitlements::TRAIL_CAMERA_INTEGRATION,
        ]);

        $this->assertCount(2, $result);
        $this->assertSame(
            array_unique($result),
            $result,
            'no duplicate keys after expansion',
        );
    }

    public function test_empty_input_yields_empty_output(): void
    {
        $this->assertSame([], $this->expand([]));
    }
}
