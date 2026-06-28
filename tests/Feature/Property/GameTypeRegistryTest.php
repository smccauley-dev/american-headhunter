<?php

namespace Tests\Feature\Property;

use App\Services\Property\PropertyService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The admin-managed game_types registry (DB 2) and the PropertyService accessors
 * that replaced the old hardcoded species constants: labels, icon map, default
 * availability, active filtering, and the FK that now guards property_species.
 */
class GameTypeRegistryTest extends TestCase
{
    private string $code;
    private string $propertyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->code       = 'test_gt_' . substr((string) Str::uuid(), 0, 8);
        $this->propertyId = (string) Str::uuid();

        DB::connection('property')->table('properties')->insert([
            'id'            => $this->propertyId,
            'owner_user_id' => (string) Str::uuid(),
            'title'         => 'Registry Tract',
            'slug'          => "registry-{$this->propertyId}",
            'status'        => 'draft',
            'state_code'    => 'TX',
            'county'        => 'Llano',
            'total_acres'   => '320.00',
        ]);

        $this->service()->forgetGameTypesCache();
    }

    protected function tearDown(): void
    {
        DB::connection('property')->table('property_species')->where('property_id', $this->propertyId)->delete();
        DB::connection('property')->table('properties')->where('id', $this->propertyId)->delete();
        DB::connection('property')->table('game_types')->where('code', $this->code)->delete();

        $this->service()->forgetGameTypesCache();

        foreach (['property', 'property_read'] as $conn) {
            try { DB::connection($conn)->disconnect(); } catch (\Throwable) {}
        }

        parent::tearDown();
    }

    private function service(): PropertyService
    {
        return app(PropertyService::class);
    }

    private function insertGameType(bool $active = true): void
    {
        DB::connection('property')->table('game_types')->insert([
            'id'                   => (string) Str::uuid(),
            'code'                 => $this->code,
            'label'                => 'Test Critter',
            'icon_svg'             => '<path d="M0 0"/>',
            'icon_viewbox'         => '0 0 24 24',
            'default_availability' => 'year_round',
            'sort_order'           => 999,
            'is_active'            => $active,
        ]);

        $this->service()->forgetGameTypesCache();
    }

    public function test_registry_exposes_seeded_labels_icons_and_defaults(): void
    {
        $labels = $this->service()->speciesLabels(false);
        $this->assertSame('Whitetail Deer', $labels['whitetail_deer'] ?? null);
        $this->assertSame('Hog', $labels['hog'] ?? null);

        $icons = $this->service()->gameIconMap();
        $this->assertNotEmpty($icons['whitetail_deer']['icon_svg'] ?? null);
        $this->assertSame('0 0 512 512', $icons['whitetail_deer']['icon_viewbox'] ?? null);

        $this->assertSame('year_round', $this->service()->defaultAvailability('hog'));
        $this->assertSame('seasonal', $this->service()->defaultAvailability('whitetail_deer'));
        $this->assertSame('seasonal', $this->service()->defaultAvailability('unknown_code'));

        $this->assertContains('whitetail_deer', $this->service()->validSpeciesCodes());
    }

    public function test_inactive_type_hidden_from_active_only_views(): void
    {
        $this->insertGameType(active: false);

        $this->assertArrayNotHasKey($this->code, $this->service()->speciesLabels(true));
        $this->assertArrayHasKey($this->code, $this->service()->speciesLabels(false));
        $this->assertContains($this->code, $this->service()->validSpeciesCodes());
    }

    public function test_foreign_key_blocks_unknown_species_code(): void
    {
        $this->expectException(QueryException::class);

        DB::connection('property')->table('property_species')->insert([
            'id'           => (string) Str::uuid(),
            'property_id'  => $this->propertyId,
            'species_code' => 'nonexistent_zzz',
            'is_primary'   => false,
            'availability' => 'seasonal',
        ]);
    }

    public function test_game_type_in_use_reflects_property_references(): void
    {
        $this->insertGameType();

        $this->assertFalse($this->service()->gameTypeInUse($this->code));

        DB::connection('property')->table('property_species')->insert([
            'id'           => (string) Str::uuid(),
            'property_id'  => $this->propertyId,
            'species_code' => $this->code,
            'is_primary'   => false,
            'availability' => 'year_round',
        ]);

        $this->assertTrue($this->service()->gameTypeInUse($this->code));
    }
}
