<?php

namespace Tests\Feature\Apply;

use App\Models\Lease\LeaseApplication;
use App\Models\Lease\LeaseApplicationHunter;
use App\Services\Platform\LegalService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature tests for the apply/submit flow after the ApplicationService extraction refactor.
 *
 * Isolation strategy:
 *   - identity, lease, documents, platform connections: wrapped in DB transactions
 *     (rolled back in tearDown — no permanent writes)
 *   - property connection: fixtures committed immediately so property_read (read replica
 *     connection, readonly credentials) can see them; manually deleted in tearDown.
 */
class SubmitApplicationTest extends TestCase
{
    private string $userId;
    private string $propertyId;
    private string $listingId;

    /** @var list<string> Extra listing ids created per-test, removed in tearDown. */
    private array $extraListingIds = [];

    private const TX_CONNECTIONS = ['identity', 'lease', 'documents', 'platform'];

    protected function setUp(): void
    {
        parent::setUp();

        // The submit route is rate-limited (throttle:5,1); this suite issues more
        // than five POSTs, which would otherwise return 429 instead of exercising
        // the controller. Throttling isn't under test here.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        foreach (self::TX_CONNECTIONS as $conn) {
            DB::connection($conn)->beginTransaction();
        }

        $this->userId     = (string) Str::uuid();
        $this->propertyId = (string) Str::uuid();
        $this->listingId  = (string) Str::uuid();
        $ownerId          = (string) Str::uuid();

        // ── Identity fixtures (inside transaction) ────────────────────────────
        DB::connection('identity')->table('users')->insert([
            'id'            => $this->userId,
            'email'         => "hunter-{$this->userId}@test.invalid",
            'password_hash' => 'test-hash',
            'status'        => 'active',
            'account_type'  => 'hunter',
        ]);
        DB::connection('identity')->table('user_profiles')->insert([
            'id'            => (string) Str::uuid(),
            'user_id'       => $this->userId,
            'first_name'    => 'John',
            'last_name'     => 'Doe',
            'date_of_birth' => '1985-06-15',
        ]);
        // Property owner user — needed because property.owner_user_id is not a DB FK,
        // but GuestHunterService / other services may validate user existence.
        DB::connection('identity')->table('users')->insert([
            'id'            => $ownerId,
            'email'         => "owner-{$ownerId}@test.invalid",
            'password_hash' => 'test-hash',
            'status'        => 'active',
            'account_type'  => 'landowner',
        ]);

        // ── Platform fixtures (inside transaction) ────────────────────────────
        // Version 99 so orderByDesc('version') picks this over any seeded version 1.
        DB::connection('platform')->table('legal_documents')->insert([
            'id'             => (string) Str::uuid(),
            'document_key'   => 'hunter_info_certification',
            'version'        => 99,
            'title'          => 'Test Certification',
            'content'        => 'I certify the accuracy of the information provided.',
            'effective_date' => now()->toDateString(),
            'is_active'      => true,
            'updated_at'     => now(),
        ]);

        // ── Property fixtures (committed so property_read connection sees them) ──
        DB::connection('property')->table('properties')->insert([
            'id'            => $this->propertyId,
            'owner_user_id' => $ownerId,
            'title'         => 'Test Ranch',
            'slug'          => 'test-ranch-' . substr($this->userId, 0, 8),
            'status'        => 'active',
            'state_code'    => 'TX',
            'county'        => 'Travis',
            'total_acres'   => '100.00',
        ]);
        DB::connection('property')->table('property_listings')->insert([
            'id'              => $this->listingId,
            'property_id'     => $this->propertyId,
            'listing_type'    => 'annual_lease',
            'status'          => 'active',
            'season_start'    => '2026-10-01',
            'season_end'      => '2026-11-30',
            'max_hunters'     => 4,
            'price_per_hunter' => '500.00',
            'deposit_percent' => 25,
            'auto_renew'      => false,
            'visibility'      => 'public',
        ]);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse(self::TX_CONNECTIONS) as $conn) {
            try {
                DB::connection($conn)->rollBack();
            } catch (\Throwable) {}
        }

        // Clean up committed property fixtures
        foreach ($this->extraListingIds as $extraId) {
            DB::connection('property')->table('property_availability')
                ->where('listing_id', $extraId)->delete();
            DB::connection('property')->table('property_listings')
                ->where('id', $extraId)->delete();
        }
        DB::connection('property')->table('property_listings')
            ->where('id', $this->listingId)->delete();
        DB::connection('property')->table('properties')
            ->where('id', $this->propertyId)->delete();

        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 1 — Happy path, full side-effect assertions
    // ─────────────────────────────────────────────────────────────────────────

    public function test_complete_application_persists_all_expected_records(): void
    {
        Storage::fake('local');
        Queue::fake();

        $response = $this->withSession(['auth.user_id' => $this->userId])
            ->post("/apply/{$this->listingId}", $this->validPayload([
                'hunters' => [$this->primaryHunterData([
                    'dl_photo'              => UploadedFile::fake()->image('dl_front.jpg', 400, 250),
                    'dl_photo_back'         => UploadedFile::fake()->image('dl_back.jpg', 400, 250),
                    'hunting_license_photo' => UploadedFile::fake()->image('license.jpg', 400, 250),
                ])],
            ]));

        // Correct redirect to status page
        $application = LeaseApplication::on('lease')
            ->where('applicant_user_id', $this->userId)
            ->first();
        $this->assertNotNull($application, 'No LeaseApplication row was created');
        $response->assertRedirect(route('apply.status', $application->id));

        // Application row written with correct values
        $this->assertDatabaseHas('lease_applications', [
            'applicant_user_id' => $this->userId,
            'listing_id'        => $this->listingId,
            'status'            => 'pending',
            'application_type'  => 'individual',
        ], 'lease');

        // Document records written for all three uploads and promoted to 'processing'
        $dlDocCount = DB::connection('documents')->table('documents')
            ->where('owner_user_id', $this->userId)
            ->where('document_type', 'driver_license')
            ->where('status', 'processing')
            ->count();
        $this->assertEquals(2, $dlDocCount, 'Expected 2 driver_license documents (front + back) in processing status');

        $this->assertDatabaseHas('documents', [
            'owner_user_id' => $this->userId,
            'document_type' => 'hunting_license',
            'status'        => 'processing',
        ], 'documents');

        // Files written to storage (3 uploads → 3 files)
        $storedFiles = Storage::disk('local')->allFiles();
        $this->assertCount(3, $storedFiles, 'Expected 3 files in storage for 3 uploads');

        // HunterCredentials synced — profile data and DL info written back
        $this->assertDatabaseHas('hunter_credentials', [
            'user_id'    => $this->userId,
            'dl_number'  => 'TX12345678',
            'dl_state'   => 'TX',
            'state_code' => 'TX',
            'city'       => 'Austin',
        ], 'identity');

        // Document IDs synced back to credentials (upload happened so IDs should be non-null)
        $creds = DB::connection('identity')->table('hunter_credentials')
            ->where('user_id', $this->userId)
            ->first();
        $this->assertNotNull($creds, 'HunterCredentials row not found');
        $this->assertNotNull($creds->dl_document_id, 'DL front document ID not synced to credentials');
        $this->assertNotNull($creds->dl_document_id_back, 'DL back document ID not synced to credentials');
        $this->assertNotNull($creds->hunting_license_document_id, 'Hunting license doc ID not synced');

        // Primary hunter snapshot persisted to lease_application_hunters
        // dl_number is encrypted at rest — assert non-encrypted fields via assertDatabaseHas,
        // then load via Eloquent so getAttribute() decrypts the sensitive columns.
        $this->assertDatabaseHas('lease_application_hunters', [
            'application_id' => $application->id,
            'hunter_type'    => 'primary',
            'first_name'     => 'John',
            'last_name'      => 'Doe',
        ], 'lease');
        $primaryHunter = LeaseApplicationHunter::on('lease')
            ->where('application_id', $application->id)
            ->where('hunter_type', 'primary')
            ->first();
        $this->assertNotNull($primaryHunter, 'Primary hunter row not found');
        $this->assertEquals('TX12345678', $primaryHunter->dl_number, 'Decrypted DL number does not match');

        // Legal certification acceptance recorded
        $this->assertDatabaseHas('user_legal_acceptances', [
            'user_id'          => $this->userId,
            'document_key'     => 'hunter_info_certification',
            'document_version' => 99,
            'context_type'     => 'lease_application',
            'context_id'       => $application->id,
        ], 'identity');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 2 — Lease transaction rolls back; document compensation runs
    //
    // Force a failure inside the lease transaction (after documents are written
    // but before it commits) by mocking LegalService::recordAcceptance to throw.
    //
    // Expected outcome:
    //   - Lease savepoint rolls back → no LeaseApplication, no legal acceptance
    //   - submitAtomically catch block calls deleteUnattachedByIds immediately
    //   - All unattached documents are soft-deleted and files removed from storage
    // ─────────────────────────────────────────────────────────────────────────

    public function test_lease_transaction_rolls_back_and_documents_are_compensated_on_failure(): void
    {
        Storage::fake('local');
        Queue::fake();

        // makePartial() lets getActiveCertification() call the real implementation (which returns
        // a ?LegalDocument and satisfies the return-type declaration). Only recordAcceptance is
        // overridden — it throws to simulate a failure inside the lease transaction.
        $legalMock = \Mockery::mock(LegalService::class)->makePartial();
        $legalMock->shouldReceive('recordAcceptance')
            ->once()
            ->andThrow(new \RuntimeException('Forced failure inside lease transaction'));
        $this->app->instance(LegalService::class, $legalMock);

        $this->withSession(['auth.user_id' => $this->userId])
            ->post("/apply/{$this->listingId}", $this->validPayload([
                'hunters' => [$this->primaryHunterData([
                    'dl_photo' => UploadedFile::fake()->image('dl_front.jpg', 400, 250),
                ])],
            ]));

        // Lease savepoint rolled back — no application written
        $this->assertDatabaseMissing('lease_applications', [
            'applicant_user_id' => $this->userId,
        ], 'lease');

        // Legal acceptance never committed (exception fired before it returned)
        $this->assertDatabaseMissing('user_legal_acceptances', [
            'user_id' => $this->userId,
        ], 'identity');

        // Immediate compensation ran: all unattached documents soft-deleted
        $uncleanedDocCount = DB::connection('documents')->table('documents')
            ->where('owner_user_id', $this->userId)
            ->whereNull('deleted_at')
            ->count();
        $this->assertEquals(
            0,
            $uncleanedDocCount,
            'Compensation (deleteUnattachedByIds) should have soft-deleted all unattached documents',
        );

        // Storage also cleaned — files removed by compensation
        $this->assertEmpty(
            Storage::disk('local')->allFiles(),
            'Compensation should have removed all files from storage',
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 3 — Validation rejection
    // ─────────────────────────────────────────────────────────────────────────

    public function test_submit_rejected_and_no_records_written_when_hunter_fields_missing(): void
    {
        $response = $this->withSession(['auth.user_id' => $this->userId])
            ->post("/apply/{$this->listingId}", [
                'application_type'       => 'individual',
                'proposed_start'         => now()->addMonth()->toDateString(),
                'proposed_end'           => now()->addMonths(2)->toDateString(),
                'certification_accepted' => true,
                'hunters'                => [[
                    'hunter_type' => 'primary',
                    'user_id'     => $this->userId,
                    // All required credential fields omitted to trigger validation failure
                ]],
            ]);

        $response->assertSessionHasErrors([
            'hunters.0.first_name',
            'hunters.0.last_name',
            'hunters.0.date_of_birth',
            'hunters.0.email',
            'hunters.0.cell_phone',
            'hunters.0.address_line1',
            'hunters.0.city',
            'hunters.0.state_code',
            'hunters.0.zip_code',
            'hunters.0.emergency_contact_name',
            'hunters.0.emergency_contact_phone',
            'hunters.0.emergency_contact_relationship',
            'hunters.0.dl_number',
            'hunters.0.dl_state',
            'hunters.0.dl_expiry',
            'hunters.0.hunting_license_number',
            'hunters.0.hunting_license_state',
            'hunters.0.hunting_license_expiry',
        ]);

        $this->assertDatabaseMissing('lease_applications', [
            'applicant_user_id' => $this->userId,
        ], 'lease');

        $this->assertDatabaseMissing('documents', [
            'owner_user_id' => $this->userId,
        ], 'documents');

        $this->assertDatabaseMissing('hunter_credentials', [
            'user_id' => $this->userId,
        ], 'identity');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 4 — Process-death simulation: reaper cleans stale unattached docs
    //
    // Simulates a case where the application process died after creating
    // unattached documents but before any compensation could run.
    // The scheduled reaper must still clean them up.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_reaper_cleans_stale_unattached_documents_after_process_death(): void
    {
        Storage::fake('local');

        $docId   = (string) Str::uuid();
        $fileKey = "documents/{$this->userId}/{$docId}.jpg";

        // Simulate a file that was stored before the process died
        Storage::disk('local')->put($fileKey, 'fake image bytes');

        // Insert the unattached document record with a timestamp older than the 2-hour threshold
        DB::connection('documents')->table('documents')->insert([
            'id'                => $docId,
            'owner_user_id'     => $this->userId,
            'document_type'     => 'driver_license',
            'status'            => 'unattached',
            'original_filename' => 'dl_front.jpg',
            'mime_type'         => 'image/jpeg',
            'size_bytes'        => 100,
            'storage_bucket'    => 'local',
            'storage_key'       => $fileKey,
            'storage_provider'  => 'garage',
            'virus_scan_status' => 'pending',
            'is_public'         => false,
            'created_at'        => now()->subHours(3),
            'updated_at'        => now()->subHours(3),
        ]);

        $this->assertTrue(Storage::disk('local')->exists($fileKey), 'Precondition: file must exist before reaper runs');

        $service = $this->app->make(\App\Services\Documents\DocumentService::class);
        $cleaned = $service->reaperCleanup(120);

        $this->assertEquals(1, $cleaned, 'Reaper should report 1 document cleaned');
        $this->assertFalse(Storage::disk('local')->exists($fileKey), 'Reaper must remove the file from storage');

        // Record is soft-deleted (not hard-deleted) — query bypasses SoftDeletes scope
        $record = DB::connection('documents')->table('documents')
            ->where('id', $docId)
            ->first();
        $this->assertNotNull($record, 'Document record should still exist (soft delete, not hard delete)');
        $this->assertNotNull($record->deleted_at, 'Reaper must set deleted_at on the document record');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 5 — Fixed-term term lock: proposed dates must equal the season
    // ─────────────────────────────────────────────────────────────────────────

    public function test_fixed_term_application_rejects_dates_other_than_the_season(): void
    {
        $response = $this->withSession(['auth.user_id' => $this->userId])
            ->post("/apply/{$this->listingId}", $this->validPayload([
                'proposed_start' => '2026-10-15',  // inside the season but not its start
                'proposed_end'   => '2026-11-15',
            ]));

        $response->assertSessionHasErrors(['proposed_start']);
        $this->assertDatabaseMissing('lease_applications', [
            'applicant_user_id' => $this->userId,
        ], 'lease');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 6 — A fixed-term listing whose season has ended is blocked
    // ─────────────────────────────────────────────────────────────────────────

    public function test_fixed_term_application_blocked_when_season_has_ended(): void
    {
        $endedListingId = $this->createListing([
            'listing_type' => 'seasonal_lease',
            'season_start' => '2024-10-01',
            'season_end'   => '2024-11-30',
        ]);

        $response = $this->withSession(['auth.user_id' => $this->userId])
            ->post("/apply/{$endedListingId}", $this->validPayload([
                'proposed_start' => '2024-10-01',
                'proposed_end'   => '2024-11-30',
            ]));

        $response->assertSessionHasErrors(['proposed_start']);
        $this->assertDatabaseMissing('lease_applications', [
            'applicant_user_id' => $this->userId,
        ], 'lease');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 7 — Hunting license state must match the property's state
    // ─────────────────────────────────────────────────────────────────────────

    public function test_application_rejected_when_hunting_license_state_mismatches_property(): void
    {
        // Property is in TX (setUp); a license issued by OK must be rejected.
        $response = $this->withSession(['auth.user_id' => $this->userId])
            ->post("/apply/{$this->listingId}", $this->validPayload([
                'hunters' => [$this->primaryHunterData([
                    'hunting_license_state' => 'OK',
                ])],
            ]));

        $response->assertSessionHasErrors(['hunters.0.hunting_license_state']);
        $this->assertDatabaseMissing('lease_applications', [
            'applicant_user_id' => $this->userId,
        ], 'lease');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 8 — Day hunt: open dates inside the season are accepted
    // ─────────────────────────────────────────────────────────────────────────

    public function test_day_hunt_accepts_open_dates_within_season(): void
    {
        Storage::fake('local');
        Queue::fake();

        $dayHuntId = $this->createListing([
            'listing_type' => 'day_hunt',
            'season_start' => '2026-10-01',
            'season_end'   => '2026-12-31',
        ]);

        $response = $this->withSession(['auth.user_id' => $this->userId])
            ->post("/apply/{$dayHuntId}", $this->validPayload([
                'proposed_start' => '2026-10-10',
                'proposed_end'   => '2026-10-12',
            ]));

        $application = LeaseApplication::on('lease')
            ->where('applicant_user_id', $this->userId)
            ->first();
        $this->assertNotNull($application, 'A valid day-hunt application should be created');
        $response->assertRedirect(route('apply.status', $application->id));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 9 — Day hunt: dates overlapping a booked range are rejected
    // ─────────────────────────────────────────────────────────────────────────

    public function test_day_hunt_rejects_dates_overlapping_unavailable_range(): void
    {
        $dayHuntId = $this->createListing([
            'listing_type' => 'day_hunt',
            'season_start' => '2026-10-01',
            'season_end'   => '2026-12-31',
        ]);

        DB::connection('property')->table('property_availability')->insert([
            'id'         => (string) Str::uuid(),
            'listing_id' => $dayHuntId,
            'date_start' => '2026-10-10',
            'date_end'   => '2026-10-14',
            'reason'     => 'booked',
        ]);

        $response = $this->withSession(['auth.user_id' => $this->userId])
            ->post("/apply/{$dayHuntId}", $this->validPayload([
                'proposed_start' => '2026-10-12',  // lands inside the booked range
                'proposed_end'   => '2026-10-16',
            ]));

        $response->assertSessionHasErrors(['proposed_start']);
        $this->assertDatabaseMissing('lease_applications', [
            'applicant_user_id' => $this->userId,
        ], 'lease');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 10 — Day hunt: dates outside the season window are rejected
    // ─────────────────────────────────────────────────────────────────────────

    public function test_day_hunt_rejects_dates_outside_season_window(): void
    {
        $dayHuntId = $this->createListing([
            'listing_type' => 'day_hunt',
            'season_start' => '2026-10-01',
            'season_end'   => '2026-10-31',
        ]);

        $response = $this->withSession(['auth.user_id' => $this->userId])
            ->post("/apply/{$dayHuntId}", $this->validPayload([
                'proposed_start' => '2026-11-05',  // after the season ends
                'proposed_end'   => '2026-11-07',
            ]));

        $response->assertSessionHasErrors(['proposed_end']);
        $this->assertDatabaseMissing('lease_applications', [
            'applicant_user_id' => $this->userId,
        ], 'lease');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /** Create an extra active listing on the test property; cleaned up in tearDown. */
    private function createListing(array $attributes): string
    {
        $id = (string) Str::uuid();
        DB::connection('property')->table('property_listings')->insert(array_merge([
            'id'               => $id,
            'property_id'      => $this->propertyId,
            'listing_type'     => 'day_hunt',
            'status'           => 'active',
            'season_start'     => '2026-10-01',
            'season_end'       => '2026-11-30',
            'max_hunters'      => 4,
            'price_per_hunter' => '500.00',
            'deposit_percent'  => 25,
            'auto_renew'       => false,
            'visibility'       => 'public',
        ], $attributes));
        $this->extraListingIds[] = $id;

        return $id;
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'application_type'       => 'individual',
            // The fixture is an annual_lease — the term is locked to the listing's
            // season, so a valid submission must propose exactly those dates.
            'proposed_start'         => '2026-10-01',
            'proposed_end'           => '2026-11-30',
            'message'                => 'Looking forward to hunting on your property.',
            'certification_accepted' => true,
            'hunters'                => [$this->primaryHunterData()],
        ], $overrides);
    }

    private function primaryHunterData(array $overrides = []): array
    {
        return array_merge([
            'hunter_type'                       => 'primary',
            'user_id'                           => $this->userId,
            'first_name'                        => 'John',
            'last_name'                         => 'Doe',
            'date_of_birth'                     => '1985-06-15',
            'email'                             => "hunter-{$this->userId}@test.invalid",
            'cell_phone'                        => '5125551234',
            'address_line1'                     => '123 Main St',
            'city'                              => 'Austin',
            'state_code'                        => 'TX',
            'zip_code'                          => '78701',
            'emergency_contact_name'            => 'Jane Doe',
            'emergency_contact_phone'           => '5125555678',
            'emergency_contact_relationship'    => 'Spouse',
            'dl_number'                         => 'TX12345678',
            'dl_state'                          => 'TX',
            'dl_expiry'                         => '2028-01-01',
            'dl_confirmed_current'              => true,
            'hunting_license_number'            => 'TX-HL-9999',
            'hunting_license_state'             => 'TX',
            'hunting_license_expiry'            => '2027-01-01',
            'hunting_license_confirmed_current' => true,
        ], $overrides);
    }
}
