<?php

namespace Tests\Feature\Property;

use App\Services\Property\PropertyMapService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression for the silent soft-delete bug: deleted_at is intentionally NOT in
 * PropertyMapImage/PropertyMapMarker $fillable, so $model->update(['deleted_at'
 * => ...]) dropped the key and the row was never deleted (the admin Map tab
 * reported success but nothing changed). The service must set deleted_at
 * directly so the write persists.
 */
class PropertyMapServiceTest extends TestCase
{
    private string $propertyId;
    private string $imageId;
    private string $secondImageId;
    private string $markerId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->propertyId    = (string) Str::uuid();
        $this->imageId       = (string) Str::uuid();
        $this->secondImageId = (string) Str::uuid();
        $this->markerId      = (string) Str::uuid();

        DB::connection('property')->table('properties')->insert([
            'id'            => $this->propertyId,
            'owner_user_id' => (string) Str::uuid(),
            'title'         => 'Delete Test Ranch',
            'slug'          => "delete-test-{$this->propertyId}",
            'status'        => 'active',
            'state_code'    => 'TX',
            'county'        => 'Llano',
            'total_acres'   => '100.00',
        ]);

        DB::connection('property')->table('property_map_images')->insert([
            'id'          => $this->imageId,
            'property_id' => $this->propertyId,
            'document_id' => (string) Str::uuid(),
            'sort_order'  => 0,
            'is_boundary' => true,
        ]);

        // Second image so deleting the boundary can promote a replacement.
        DB::connection('property')->table('property_map_images')->insert([
            'id'          => $this->secondImageId,
            'property_id' => $this->propertyId,
            'document_id' => (string) Str::uuid(),
            'sort_order'  => 1,
            'is_boundary' => false,
        ]);

        DB::connection('property')->table('property_map_markers')->insert([
            'id'           => $this->markerId,
            'map_image_id' => $this->imageId,
            'label'        => 'North Stand',
            'marker_type'  => 'stand',
            'x_percent'    => '40.000',
            'y_percent'    => '30.000',
        ]);
    }

    protected function tearDown(): void
    {
        DB::connection('property')->table('properties')->where('id', $this->propertyId)->delete();

        foreach (['property', 'property_read'] as $conn) {
            try {
                DB::connection($conn)->disconnect();
            } catch (\Throwable) {
            }
        }

        parent::tearDown();
    }

    private function deletedAt(string $table, string $id): ?string
    {
        return DB::connection('property')->table($table)->where('id', $id)->value('deleted_at');
    }

    public function test_delete_map_image_persists_soft_delete(): void
    {
        $this->assertNull($this->deletedAt('property_map_images', $this->imageId));

        app(PropertyMapService::class)->deleteMapImage($this->imageId);

        $this->assertNotNull(
            $this->deletedAt('property_map_images', $this->imageId),
            'deleteMapImage() must persist deleted_at (mass-assignment would silently drop it).'
        );

        // Deleting the boundary promotes the remaining image to boundary.
        $this->assertTrue((bool) DB::connection('property')->table('property_map_images')
            ->where('id', $this->secondImageId)->value('is_boundary'));
    }

    public function test_restore_map_image_clears_soft_delete(): void
    {
        $service = app(PropertyMapService::class);
        $service->deleteMapImage($this->imageId);
        $this->assertNotNull($this->deletedAt('property_map_images', $this->imageId));

        $service->restoreMapImage($this->imageId);

        $this->assertNull(
            $this->deletedAt('property_map_images', $this->imageId),
            'restoreMapImage() must clear deleted_at.'
        );
    }

    public function test_delete_marker_persists_soft_delete(): void
    {
        $this->assertNull($this->deletedAt('property_map_markers', $this->markerId));

        app(PropertyMapService::class)->deleteMarker($this->markerId);

        $this->assertNotNull(
            $this->deletedAt('property_map_markers', $this->markerId),
            'deleteMarker() must persist deleted_at.'
        );
    }
}
