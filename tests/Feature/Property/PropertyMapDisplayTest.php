<?php

namespace Tests\Feature\Property;

use App\Models\Property\PropertyMapMarker;
use App\Services\Property\PropertyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PropertyService::getMapImagesForDisplay — the member-portal Map tab read path
 * (Slice 6). Pins boundary-first ordering, that soft-deleted images and markers
 * are excluded, and that each marker is decorated with its type label and pin
 * colour for the editor.
 */
class PropertyMapDisplayTest extends TestCase
{
    private string $ownerId;
    private string $propertyId;
    private string $plainImageId;
    private string $boundaryImageId;
    private string $deletedImageId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ownerId         = (string) Str::uuid();
        $this->propertyId      = (string) Str::uuid();
        $this->plainImageId    = (string) Str::uuid();
        $this->boundaryImageId = (string) Str::uuid();
        $this->deletedImageId  = (string) Str::uuid();

        DB::connection('identity')->table('users')->insert([
            'id'            => $this->ownerId,
            'email'         => "owner-{$this->ownerId}@test.invalid",
            'password_hash' => 'test-hash',
            'status'        => 'active',
            'account_type'  => 'landowner',
        ]);

        DB::connection('property')->table('properties')->insert([
            'id'            => $this->propertyId,
            'owner_user_id' => $this->ownerId,
            'title'         => 'Map Tract',
            'slug'          => "map-tract-{$this->propertyId}",
            'status'        => 'active',
            'state_code'    => 'TX',
            'county'        => 'Llano',
            'total_acres'   => '640.00',
        ]);

        // A plain image (sort 0), a boundary image (sort 1) that must sort FIRST,
        // and a soft-deleted image that must be excluded entirely.
        DB::connection('property')->table('property_map_images')->insert([
            [
                'id'          => $this->plainImageId,
                'property_id' => $this->propertyId,
                'document_id' => (string) Str::uuid(),
                'description' => 'Aerial',
                'sort_order'  => 0,
                'is_boundary' => false,
                'deleted_at'  => null,
            ],
            [
                'id'          => $this->boundaryImageId,
                'property_id' => $this->propertyId,
                'document_id' => (string) Str::uuid(),
                'description' => 'Surveyed boundary',
                'sort_order'  => 1,
                'is_boundary' => true,
                'deleted_at'  => null,
            ],
            [
                'id'          => $this->deletedImageId,
                'property_id' => $this->propertyId,
                'document_id' => (string) Str::uuid(),
                'description' => 'Old draft',
                'sort_order'  => 2,
                'is_boundary' => false,
                'deleted_at'  => now(),
            ],
        ]);

        // Two live markers on the boundary image plus one soft-deleted marker.
        DB::connection('property')->table('property_map_markers')->insert([
            [
                'id'           => (string) Str::uuid(),
                'map_image_id' => $this->boundaryImageId,
                'label'        => 'North Stand',
                'marker_type'  => 'stand',
                'x_percent'    => '40.000',
                'y_percent'    => '30.000',
                'deleted_at'   => null,
            ],
            [
                'id'           => (string) Str::uuid(),
                'map_image_id' => $this->boundaryImageId,
                'label'        => 'Creek Crossing',
                'marker_type'  => 'water',
                'x_percent'    => '55.000',
                'y_percent'    => '60.000',
                'deleted_at'   => null,
            ],
            [
                'id'           => (string) Str::uuid(),
                'map_image_id' => $this->boundaryImageId,
                'label'        => 'Removed Camera',
                'marker_type'  => 'camera',
                'x_percent'    => '10.000',
                'y_percent'    => '10.000',
                'deleted_at'   => now(),
            ],
        ]);
    }

    protected function tearDown(): void
    {
        DB::connection('property')->table('property_map_markers')
            ->whereIn('map_image_id', [$this->plainImageId, $this->boundaryImageId, $this->deletedImageId])->delete();
        DB::connection('property')->table('property_map_images')->where('property_id', $this->propertyId)->delete();
        DB::connection('property')->table('properties')->where('id', $this->propertyId)->delete();
        DB::connection('identity')->table('users')->where('id', $this->ownerId)->delete();

        foreach (['property', 'property_read', 'identity'] as $conn) {
            try { DB::connection($conn)->disconnect(); } catch (\Throwable) {}
        }

        parent::tearDown();
    }

    private function service(): PropertyService
    {
        return app(PropertyService::class);
    }

    public function test_boundary_image_sorts_first_and_deleted_image_is_excluded(): void
    {
        $images = $this->service()->getMapImagesForDisplay($this->propertyId);

        $this->assertCount(2, $images);
        $this->assertSame($this->boundaryImageId, $images[0]['id']);
        $this->assertTrue($images[0]['is_boundary']);
        $this->assertSame($this->plainImageId, $images[1]['id']);
        $this->assertNotContains($this->deletedImageId, array_column($images, 'id'));
    }

    public function test_live_markers_are_decorated_and_deleted_markers_excluded(): void
    {
        $images = $this->service()->getMapImagesForDisplay($this->propertyId);
        $markers = $images[0]['markers'];

        $this->assertCount(2, $markers);
        $this->assertNotContains('Removed Camera', array_column($markers, 'label'));

        $stand = collect($markers)->firstWhere('label', 'North Stand');
        $this->assertSame('stand', $stand['marker_type']);
        $this->assertSame(PropertyMapMarker::TYPES['stand'], $stand['type_label']);
        $this->assertSame(PropertyMapMarker::TYPE_COLORS['stand'], $stand['color']);
        $this->assertSame(40.0, $stand['x_percent']);
        $this->assertIsFloat($stand['x_percent']);
    }

    public function test_plain_image_has_no_markers(): void
    {
        $images = $this->service()->getMapImagesForDisplay($this->propertyId);

        $plain = collect($images)->firstWhere('id', $this->plainImageId);
        $this->assertSame([], $plain['markers']);
    }
}
