<?php

namespace Tests\Feature\Property;

use App\Services\Property\PropertyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PropertyService photo gallery surface — the member-portal Photos tab (Slice 6).
 * Pins display ordering, the "set cover" flow (which also stamps the property's
 * primary_photo_document_id), reorder re-sequencing, and soft-delete with primary
 * promotion. Photos carry a document_id that references DB 11 with no cross-DB FK,
 * so fake UUIDs are safe here — deletePhoto's document soft-delete is wrapped in
 * try/catch and tolerates a missing document.
 */
class PropertyPhotosTest extends TestCase
{
    private string $ownerId;
    private string $propertyId;
    /** @var array<int,string> photo ids in insertion order */
    private array $photoIds = [];
    /** @var array<int,string> document ids parallel to $photoIds */
    private array $docIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->ownerId    = (string) Str::uuid();
        $this->propertyId = (string) Str::uuid();

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
            'title'         => 'Photo Tract',
            'slug'          => "photo-tract-{$this->propertyId}",
            'status'        => 'active',
            'state_code'    => 'TX',
            'county'        => 'Llano',
            'total_acres'   => '640.00',
        ]);

        // Three photos, sort_order 0/1/2; the first is the cover.
        foreach (range(0, 2) as $i) {
            $photoId = (string) Str::uuid();
            $docId   = (string) Str::uuid();
            $this->photoIds[$i] = $photoId;
            $this->docIds[$i]   = $docId;

            DB::connection('property')->table('property_photos')->insert([
                'id'          => $photoId,
                'property_id' => $this->propertyId,
                'document_id' => $docId,
                'sort_order'  => $i,
                'caption'     => "Photo {$i}",
                'is_primary'  => $i === 0,
            ]);
        }

        DB::connection('property')->table('properties')
            ->where('id', $this->propertyId)
            ->update(['primary_photo_document_id' => $this->docIds[0]]);
    }

    protected function tearDown(): void
    {
        DB::connection('property')->table('property_photos')->where('property_id', $this->propertyId)->delete();
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

    private function primaryDocumentId(): ?string
    {
        return DB::connection('property')->table('properties')
            ->where('id', $this->propertyId)->value('primary_photo_document_id');
    }

    public function test_get_photos_for_display_returns_in_sort_order_with_primary_flag(): void
    {
        $photos = $this->service()->getPhotosForDisplay($this->propertyId);

        $this->assertCount(3, $photos);
        $this->assertSame($this->photoIds[0], $photos[0]['id']);
        $this->assertSame($this->photoIds[2], $photos[2]['id']);
        $this->assertTrue($photos[0]['is_primary']);
        $this->assertFalse($photos[1]['is_primary']);
        $this->assertSame([], $photos[0]['tags']);
    }

    public function test_set_primary_photo_moves_the_cover_and_stamps_the_property(): void
    {
        $this->service()->setPrimaryPhoto($this->photoIds[2]);

        $photos = collect($this->service()->getPhotosForDisplay($this->propertyId))->keyBy('id');
        $this->assertFalse($photos[$this->photoIds[0]]['is_primary']);
        $this->assertTrue($photos[$this->photoIds[2]]['is_primary']);
        $this->assertSame($this->docIds[2], $this->primaryDocumentId());
    }

    public function test_move_photo_down_swaps_with_its_neighbour(): void
    {
        $this->service()->movePhoto($this->photoIds[0], 'down');

        $ordered = array_column($this->service()->getPhotosForDisplay($this->propertyId), 'id');
        $this->assertSame([$this->photoIds[1], $this->photoIds[0], $this->photoIds[2]], $ordered);
    }

    public function test_delete_primary_photo_soft_deletes_and_promotes_the_next(): void
    {
        $this->service()->deletePhoto($this->photoIds[0]);

        $photos = $this->service()->getPhotosForDisplay($this->propertyId);
        $ids = array_column($photos, 'id');

        $this->assertCount(2, $photos);
        $this->assertNotContains($this->photoIds[0], $ids);

        // Next photo by sort order is promoted to cover, and the property follows.
        $promoted = collect($photos)->firstWhere('is_primary', true);
        $this->assertSame($this->photoIds[1], $promoted['id']);
        $this->assertSame($this->docIds[1], $this->primaryDocumentId());
    }
}
