<?php

namespace Tests\Feature\Api;

use App\Jobs\Documents\ScanDocumentForViruses;
use App\Services\Wildlife\HarvestService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Harvest field-photo upload (Phase 6.4). Photos are untrusted input: the
 * endpoint re-encodes the image (stripping EXIF GPS — SEC-024) and hands it to
 * DocumentService, which queues the existing virus scan; the document id is
 * appended to the harvest's field_photos but the photo is not servable until
 * the scan marks it 'ready'. Standing is re-enforced — an unrelated caller gets
 * a 404 (existence is never disclosed), the same contract as the read path.
 */
class HarvestPhotoTest extends TestCase
{
    private string $lesseeId;

    private string $strangerId;

    private string $propertyId;

    private string $leaseId;

    private string $applicationId;

    private string $lesseeToken;

    private string $strangerToken;

    private string $disk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disk = config('filesystems.defaults.documents', 'local');
        Storage::fake($this->disk);
        Queue::fake();

        $this->lesseeId = (string) Str::uuid();
        $this->strangerId = (string) Str::uuid();
        $this->propertyId = (string) Str::uuid();
        $this->leaseId = (string) Str::uuid();
        $this->applicationId = (string) Str::uuid();

        $this->lesseeToken = $this->makeUser($this->lesseeId, 'lessee');
        $this->strangerToken = $this->makeUser($this->strangerId, 'stranger');

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

        foreach ([$this->lesseeId, $this->strangerId] as $uid) {
            DB::connection('documents')->table('documents')->where('owner_user_id', $uid)->delete();
            DB::connection('identity')->table('personal_access_tokens')->where('tokenable_id', $uid)->delete();
            DB::connection('identity')->table('user_profiles')->where('user_id', $uid)->delete();
            DB::connection('identity')->table('users')->where('id', $uid)->delete();
        }

        foreach (['identity', 'lease', 'wildlife', 'documents'] as $conn) {
            try {
                DB::connection($conn)->disconnect();
            } catch (\Throwable) {
            }
        }

        parent::tearDown();
    }

    private function makeUser(string $id, string $label): string
    {
        $password = 'HarvestPhoto123!';
        $email = "{$label}-{$id}@example.com";

        DB::connection('identity')->table('users')->insert([
            'id' => $id,
            'email' => $email,
            'password_hash' => Hash::make($password),
            'account_type' => 'hunter',
            'status' => 'active',
            'trust_score' => 75,
            'is_veteran' => false,
            'failed_login_attempts' => 0,
        ]);
        DB::connection('identity')->table('user_profiles')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $id,
            'first_name' => ucfirst($label),
            'last_name' => 'Tester',
        ]);

        return $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password])->json('token');
    }

    private function seedHarvest(): string
    {
        return app(HarvestService::class)->log($this->lesseeId, $this->leaseId, [
            'species_code' => 'whitetail_deer',
            'harvest_date' => '2026-11-15',
            'weapon_type' => 'bow',
        ])->id;
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

    // ── Happy path ───────────────────────────────────────────────────────────────

    public function test_owner_attaches_a_photo_which_is_queued_for_scan(): void
    {
        $harvestId = $this->seedHarvest();

        $response = $this->withToken($this->lesseeToken)
            ->post("/api/v1/harvests/{$harvestId}/photos", ['photo' => $this->upload($this->jpegBytes())]);

        $response->assertStatus(201)
            ->assertJsonPath('document.status', 'processing');

        $documentId = $response->json('document.id');

        // The document is not servable yet — it is queued for the existing scan job.
        Queue::assertPushed(ScanDocumentForViruses::class);

        // The document id was appended to the harvest's field_photos.
        $photos = DB::connection('wildlife')->table('harvest_logs')->where('id', $harvestId)->value('field_photos');
        $this->assertContains($documentId, json_decode($photos, true));
    }

    // ── EXIF GPS is stripped on ingest (SEC-024) ─────────────────────────────────

    public function test_upload_strips_embedded_metadata(): void
    {
        $harvestId = $this->seedHarvest();

        $withExif = $this->jpegWithExif();
        $this->assertStringContainsString('Exif', $withExif); // the input carries a metadata segment

        $documentId = $this->withToken($this->lesseeToken)
            ->post("/api/v1/harvests/{$harvestId}/photos", ['photo' => $this->upload($withExif)])
            ->assertStatus(201)
            ->json('document.id');

        $key = DB::connection('documents')->table('documents')->where('id', $documentId)->value('storage_key');
        $stored = Storage::disk($this->disk)->get($key);

        // The re-encoded image no longer carries the EXIF segment.
        $this->assertStringNotContainsString('Exif', $stored);
    }

    // ── Standing boundary ────────────────────────────────────────────────────────

    public function test_stranger_cannot_attach_a_photo(): void
    {
        $harvestId = $this->seedHarvest();

        $this->withToken($this->strangerToken)
            ->post("/api/v1/harvests/{$harvestId}/photos", ['photo' => $this->upload($this->jpegBytes())])
            ->assertStatus(404);

        $photos = DB::connection('wildlife')->table('harvest_logs')->where('id', $harvestId)->value('field_photos');
        $this->assertSame([], json_decode($photos, true));
    }

    public function test_non_image_is_rejected(): void
    {
        $harvestId = $this->seedHarvest();

        $this->withToken($this->lesseeToken)
            ->postJson("/api/v1/harvests/{$harvestId}/photos", [])
            ->assertStatus(422);
    }
}
