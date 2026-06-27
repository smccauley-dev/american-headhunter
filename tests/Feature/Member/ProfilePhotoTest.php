<?php

namespace Tests\Feature\Member;

use App\Models\Documents\Document;
use App\Models\Identity\ProfilePhoto;
use App\Services\Documents\DocumentService;
use App\Services\Identity\ProfilePhotoService;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Profile-gallery photo extensions: the DB 1 profile_photos metadata layer over
 * DB 11 documents (caption, controlled tags, opt-in location, ordering) plus the
 * member routes that drive it. Rows live on the real identity/documents
 * connections and are removed in tearDown.
 */
class ProfilePhotoTest extends TestCase
{
    private string $userId;
    private ProfilePhotoService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        $this->userId  = (string) Str::uuid();
        $this->service = app(ProfilePhotoService::class);

        DB::connection('identity')->table('users')->insert([
            'id'            => $this->userId,
            'email'         => "photos-{$this->userId}@test.invalid",
            'password_hash' => 'test-hash',
            'status'        => 'active',
            'account_type'  => 'hunter',
        ]);
    }

    protected function tearDown(): void
    {
        $docIds = DB::connection('documents')->table('documents')
            ->where('owner_user_id', $this->userId)->pluck('id')->all();

        DB::connection('identity')->table('profile_photos')->where('user_id', $this->userId)->delete();
        DB::connection('documents')->table('documents')->where('owner_user_id', $this->userId)->delete();
        DB::connection('identity')->table('users')->where('id', $this->userId)->delete();

        parent::tearDown();
    }

    private function makePhoto(): ProfilePhoto
    {
        $doc = app(DocumentService::class)->register(
            ownerUserId:      $this->userId,
            documentType:     'profile_photo',
            originalFilename: 'shot.jpg',
            mimeType:         'image/jpeg',
            sizeBytes:        1024,
            storageBucket:    'local',
            storageKey:       'profile_photos/' . $this->userId . '/' . Str::uuid() . '.jpg',
            storageProvider:  'garage',
        );

        return $this->service->createForUpload($this->userId, $doc);
    }

    public function test_create_assigns_incrementing_sort_order(): void
    {
        $first  = $this->makePhoto();
        $second = $this->makePhoto();

        $this->assertSame(0, $first->sort_order);
        $this->assertSame(1, $second->sort_order);
    }

    public function test_update_sanitizes_tags_and_trims_caption(): void
    {
        $photo = $this->makePhoto();

        $updated = $this->service->updateMeta($this->userId, $photo->document_id, [
            'caption' => '  Opening Day Buck  ',
            'tags'    => ['whitetail', 'not_a_real_tag', '', 'rifle', 'whitetail'],
        ]);

        $this->assertSame('Opening Day Buck', $updated->caption);
        $this->assertEqualsCanonicalizing(['whitetail', 'rifle'], $updated->tags);
    }

    public function test_location_requires_both_coordinates(): void
    {
        $photo = $this->makePhoto();

        $latOnly = $this->service->updateMeta($this->userId, $photo->document_id, [
            'latitude' => '35.5', 'longitude' => '',
        ]);
        $this->assertNull($latOnly->latitude);
        $this->assertNull($latOnly->longitude);

        $both = $this->service->updateMeta($this->userId, $photo->document_id, [
            'latitude' => '35.5', 'longitude' => '-82.5',
        ]);
        $this->assertSame(35.5, $both->latitude);
        $this->assertSame(-82.5, $both->longitude);
    }

    public function test_reorder_persists_new_order(): void
    {
        $a = $this->makePhoto();
        $b = $this->makePhoto();
        $c = $this->makePhoto();

        $this->service->reorder($this->userId, [$c->document_id, $a->document_id, $b->document_id]);

        $ordered = $this->service->listForUser($this->userId);
        $this->assertSame(
            [$c->document_id, $a->document_id, $b->document_id],
            array_column($ordered, 'id'),
        );
    }

    public function test_delete_soft_deletes_metadata_and_document(): void
    {
        $photo = $this->makePhoto();

        $this->service->delete($this->userId, $photo->document_id);

        $this->assertNull(ProfilePhoto::where('document_id', $photo->document_id)->first());
        $this->assertNull(Document::where('id', $photo->document_id)->whereNull('deleted_at')->first());
    }

    public function test_another_user_cannot_update_a_photo(): void
    {
        $photo = $this->makePhoto();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->service->updateMeta((string) Str::uuid(), $photo->document_id, ['caption' => 'hijack']);
    }

    public function test_profile_route_serves_photo_metadata_and_tag_vocabulary(): void
    {
        $photo = $this->makePhoto();
        $this->service->updateMeta($this->userId, $photo->document_id, [
            'caption' => 'Ridge Line',
            'tags'    => ['whitetail'],
        ]);

        $this->withSession(['auth.user_id' => $this->userId])
            ->get('/member/profile')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Member/Profile/Hunter', false)
                ->has('photo_tags')
                ->has('photos', 1)
                ->where('photos.0.id', $photo->document_id)
                ->where('photos.0.caption', 'Ridge Line')
                ->where('photos.0.tags', ['whitetail'])
                ->where('photos.0.has_exif_gps', false)
            );
    }

    public function test_update_route_persists_metadata(): void
    {
        $photo = $this->makePhoto();

        $this->withSession(['auth.user_id' => $this->userId])
            ->patch("/member/profile/photos/{$photo->document_id}", [
                'caption' => 'Frosty Morning',
                'tags'    => ['turkey', 'bogus'],
            ])
            ->assertRedirect(route('member.profile'));

        $fresh = ProfilePhoto::where('document_id', $photo->document_id)->first();
        $this->assertSame('Frosty Morning', $fresh->caption);
        $this->assertSame(['turkey'], $fresh->tags);
    }
}
