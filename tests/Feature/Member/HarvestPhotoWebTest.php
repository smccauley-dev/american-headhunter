<?php

namespace Tests\Feature\Member;

use App\Jobs\Documents\ScanDocumentForViruses;
use App\Services\Documents\DocumentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Harvest photos on the member web form (Slice 1): each upload is attached to
 * the harvest (EXIF-stripped by default — SEC-024), virus-scanned before it is
 * servable, and mirrored into the profile Photos gallery auto-tagged with the
 * species. The "keep location data" opt-in stores the ORIGINAL bytes (EXIF GPS
 * intact) and must flag the gallery row is_location_private so it can never be
 * publicly served (SEC-061).
 */
class HarvestPhotoWebTest extends TestCase
{
    private string $lesseeId;

    private string $propertyId;

    private string $leaseId;

    private string $applicationId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake(config('filesystems.defaults.documents', 'local'));
        Queue::fake();

        $this->lesseeId = (string) Str::uuid();
        $this->propertyId = (string) Str::uuid();
        $this->leaseId = (string) Str::uuid();
        $this->applicationId = (string) Str::uuid();

        DB::connection('identity')->table('users')->insert([
            'id' => $this->lesseeId,
            'email' => "photo-web-{$this->lesseeId}@example.com",
            'password_hash' => Hash::make('HarvestPhotoWeb123!'),
            'account_type' => 'hunter',
            'status' => 'active',
            'trust_score' => 75,
            'is_veteran' => false,
            'failed_login_attempts' => 0,
        ]);
        DB::connection('identity')->table('user_profiles')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $this->lesseeId,
            'first_name' => 'Photo',
            'last_name' => 'Tester',
        ]);

        DB::connection('lease')->table('lease_applications')->insert([
            'id' => $this->applicationId,
            'listing_id' => (string) Str::uuid(),
            'applicant_user_id' => $this->lesseeId,
            'application_type' => 'individual',
            'status' => 'approved',
        ]);
        DB::connection('lease')->table('leases')->insert([
            'id' => $this->leaseId,
            'application_id' => $this->applicationId,
            'property_id' => $this->propertyId,
            'listing_id' => (string) Str::uuid(),
            'lessee_user_id' => $this->lesseeId,
            'lessor_user_id' => (string) Str::uuid(),
            'status' => 'active',
            'start_date' => '2026-10-01',
            'end_date' => '2026-11-30',
            'total_price' => '3000.00',
            'deposit_paid' => '0.00',
        ]);
    }

    protected function tearDown(): void
    {
        DB::connection('wildlife')->table('harvest_logs')->where('lease_id', $this->leaseId)->delete();

        DB::connection('lease')->table('leases')->where('id', $this->leaseId)->delete();
        DB::connection('lease')->table('lease_applications')->where('id', $this->applicationId)->delete();

        DB::connection('identity')->table('profile_photos')->where('user_id', $this->lesseeId)->delete();
        DB::connection('documents')->table('documents')->where('owner_user_id', $this->lesseeId)->delete();
        DB::connection('identity')->table('user_profiles')->where('user_id', $this->lesseeId)->delete();
        DB::connection('identity')->table('users')->where('id', $this->lesseeId)->delete();

        foreach (['identity', 'lease', 'wildlife', 'documents'] as $conn) {
            try {
                DB::connection($conn)->disconnect();
            } catch (\Throwable) {
            }
        }

        parent::tearDown();
    }

    /** @return array<string,mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'lease_id' => $this->leaseId,
            'species_code' => 'whitetail_deer',
            'weapon_type' => 'bow',
            'harvest_date' => '2026-06-15',
        ], $overrides);
    }

    /** A minimal but structurally valid JPEG. */
    private function jpegBytes(): string
    {
        $img = imagecreatetruecolor(8, 8);
        ob_start();
        imagejpeg($img);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }

    /** The same JPEG with an EXIF (APP1) segment spliced in after the SOI marker. */
    private function jpegWithExif(): string
    {
        $jpeg = $this->jpegBytes();
        $exif = "Exif\x00\x00".str_repeat("\x00", 32);
        $app1 = "\xFF\xE1".pack('n', strlen($exif) + 2).$exif;

        return "\xFF\xD8".$app1.substr($jpeg, 2);
    }

    private function upload(string $bytes): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('harvest.jpg', $bytes);
    }

    /** @return array{0:string,1:object,2:object} [documentId, documents row, profile_photos row] */
    private function attachedArtifacts(): array
    {
        $photos = DB::connection('wildlife')->table('harvest_logs')
            ->where('lease_id', $this->leaseId)->value('field_photos');
        $ids = json_decode((string) $photos, true);
        $this->assertCount(1, $ids);

        $documentId = $ids[0];
        $doc = DB::connection('documents')->table('documents')->where('id', $documentId)->first();
        $gallery = DB::connection('identity')->table('profile_photos')->where('document_id', $documentId)->first();

        $this->assertNotNull($doc);
        $this->assertNotNull($gallery);

        return [$documentId, $doc, $gallery];
    }

    // ── Default path: EXIF stripped + mirrored to the gallery ────────────────────

    public function test_photo_is_stripped_scanned_and_mirrored_to_gallery(): void
    {
        $withExif = $this->jpegWithExif();
        $this->assertStringContainsString('Exif', $withExif);

        $this->withSession(['auth.user_id' => $this->lesseeId])
            ->post('/member/harvest', $this->payload(['photos' => [$this->upload($withExif)]]))
            ->assertRedirect('/member/harvest')
            ->assertSessionHas('success');

        [, $doc, $gallery] = $this->attachedArtifacts();

        // Stored bytes were re-encoded — the EXIF segment is gone (SEC-024).
        $disk = config('filesystems.defaults.documents', 'local');
        $this->assertStringNotContainsString('Exif', Storage::disk($disk)->get($doc->storage_key));

        // Queued for the virus scan; not servable until it marks ready.
        Queue::assertPushed(ScanDocumentForViruses::class);
        $this->assertSame('photo', $doc->document_type);
        $this->assertSame('processing', $doc->status);

        // Mirrored to the profile gallery, species-tagged, location not private.
        $this->assertSame($this->lesseeId, $gallery->user_id);
        $this->assertSame(['whitetail'], json_decode($gallery->tags, true));
        $this->assertSame('Whitetail Deer harvest', $gallery->caption);
        $this->assertFalse((bool) $gallery->is_location_private);
    }

    // ── Opt-in: original bytes kept, gallery row flagged private (SEC-061) ───────

    public function test_keep_location_retains_exif_and_flags_gallery_row_private(): void
    {
        $withExif = $this->jpegWithExif();

        $this->withSession(['auth.user_id' => $this->lesseeId])
            ->post('/member/harvest', $this->payload([
                'photos' => [$this->upload($withExif)],
                'keep_photo_location' => true,
            ]))
            ->assertRedirect('/member/harvest');

        [, $doc, $gallery] = $this->attachedArtifacts();

        // The original bytes — EXIF segment intact — were stored as-is.
        $disk = config('filesystems.defaults.documents', 'local');
        $this->assertStringContainsString('Exif', Storage::disk($disk)->get($doc->storage_key));

        // Still untrusted input: virus-scanned like any other upload.
        Queue::assertPushed(ScanDocumentForViruses::class);

        // The SEC-061 guardrail: never publicly servable.
        $this->assertTrue((bool) $gallery->is_location_private);
    }

    // ── Offline dedup replay must not duplicate photos ───────────────────────────

    public function test_replayed_submission_does_not_reattach_photos(): void
    {
        $localId = (string) Str::uuid();

        foreach (range(1, 2) as $attempt) {
            $this->withSession(['auth.user_id' => $this->lesseeId])
                ->post('/member/harvest', $this->payload([
                    'local_record_id' => $localId,
                    'photos' => [$this->upload($this->jpegBytes())],
                ]))
                ->assertRedirect('/member/harvest');
        }

        // One harvest, one attached photo, one gallery mirror — the replay was a no-op.
        $this->attachedArtifacts();
        $this->assertSame(1, DB::connection('identity')->table('profile_photos')
            ->where('user_id', $this->lesseeId)->count());
    }

    // ── Serve path: scan outcome is enforced ─────────────────────────────────────

    public function test_quarantined_photo_is_not_servable_and_gets_no_thumbnail(): void
    {
        $this->withSession(['auth.user_id' => $this->lesseeId])
            ->post('/member/harvest', $this->payload(['photos' => [$this->upload($this->jpegBytes())]]))
            ->assertRedirect('/member/harvest');

        [$documentId] = $this->attachedArtifacts();
        $documents = app(DocumentService::class);

        // While processing: no thumbnail URL on the harvest index yet.
        $this->withSession(['auth.user_id' => $this->lesseeId])
            ->get('/member/harvest')
            ->assertInertia(fn (Assert $page) => $page->has('harvests.0.photo_urls', 0));

        // Scan clears it: thumbnail appears and the owner can fetch it.
        $documents->markReady($documentId);
        $this->withSession(['auth.user_id' => $this->lesseeId])
            ->get('/member/harvest')
            ->assertInertia(fn (Assert $page) => $page->has('harvests.0.photo_urls', 1));
        $this->withSession(['auth.user_id' => $this->lesseeId])
            ->get(route('member.profile.photos.serve', $documentId))
            ->assertOk();

        // Scan quarantines it: not servable even to the owner, thumbnail gone.
        $documents->markQuarantined($documentId);
        $this->withSession(['auth.user_id' => $this->lesseeId])
            ->get(route('member.profile.photos.serve', $documentId))
            ->assertNotFound();
        $this->withSession(['auth.user_id' => $this->lesseeId])
            ->get('/member/harvest')
            ->assertInertia(fn (Assert $page) => $page->has('harvests.0.photo_urls', 0));
    }
}
