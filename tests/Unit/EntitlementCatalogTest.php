<?php

namespace Tests\Unit;

use App\Support\Entitlements;
use ReflectionClass;
use Tests\TestCase;

/**
 * Guards the entitlement catalog (Entitlements::DEFINITIONS) that drives the
 * admin picker — it must stay in lockstep with the feature-key constants and
 * expose well-formed grouped options.
 */
class EntitlementCatalogTest extends TestCase
{
    /** All scalar string feature-key constants on the Entitlements class. */
    private function keyConstants(): array
    {
        return array_filter(
            (new ReflectionClass(Entitlements::class))->getConstants(),
            fn ($v) => is_string($v),
        );
    }

    public function test_catalog_covers_exactly_the_feature_key_constants(): void
    {
        $constants = array_values($this->keyConstants());
        $catalog   = array_keys(Entitlements::DEFINITIONS);

        sort($constants);
        sort($catalog);

        $this->assertSame(
            $constants,
            $catalog,
            'Every feature-key constant must have a catalog entry and vice versa.',
        );
    }

    public function test_every_definition_has_a_valid_type_and_group(): void
    {
        foreach (Entitlements::DEFINITIONS as $key => $def) {
            $this->assertArrayHasKey('label', $def, "{$key} missing label");
            $this->assertContains($def['type'], ['boolean', 'integer', 'string', 'json'], "{$key} has invalid type");
            $this->assertNotEmpty($def['group'], "{$key} missing group");
        }
    }

    public function test_implication_keys_are_all_catalogued(): void
    {
        foreach (Entitlements::IMPLIES as $from => $tos) {
            $this->assertArrayHasKey($from, Entitlements::DEFINITIONS, "implication source {$from} not catalogued");
            foreach ($tos as $to) {
                $this->assertArrayHasKey($to, Entitlements::DEFINITIONS, "implied key {$to} not catalogued");
            }
        }
    }

    public function test_grouped_options_excludes_used_keys(): void
    {
        $options = Entitlements::groupedOptions([Entitlements::TRAIL_CAMERA_INTEGRATION]);
        $flat    = array_merge(...array_values($options));

        $this->assertArrayNotHasKey(Entitlements::TRAIL_CAMERA_INTEGRATION, $flat);
        $this->assertArrayHasKey(Entitlements::DIGITAL_ID_CARD, $flat);
    }

    public function test_type_for_resolves_catalog_type_and_null_for_unknown(): void
    {
        $this->assertSame('integer', Entitlements::typeFor(Entitlements::SAVED_SEARCHES_LIMIT));
        $this->assertSame('boolean', Entitlements::typeFor(Entitlements::TRAIL_CAMERA_INTEGRATION));
        $this->assertNull(Entitlements::typeFor('not_a_real_key'));
    }
}
