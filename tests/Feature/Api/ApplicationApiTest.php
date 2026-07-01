<?php

namespace Tests\Feature\Api;

use App\Models\Identity\User;
use App\Services\Platform\EntitlementService;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Phase C mobile API — lease applications (index / show / apply / withdraw).
 *
 * Isolation strategy mirrors SubmitApplicationTest:
 *   - identity, lease, documents, platform: wrapped in transactions (rolled back).
 *   - property: committed so the property_read replica connection (findListing)
 *     can see it; removed by hand in tearDown.
 * Auth is a Sanctum personal-access token with the hunter:apply ability. Tests
 * run as the DB owner (RLS bypassed), so no per-user RLS context is needed.
 */
class ApplicationApiTest extends TestCase
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

        // Inline throttle:N,1 keys its bucket by domain+IP (not per-route), so
        // every apply request in this class would share one bucket. Throttling
        // is covered elsewhere; disable it here to exercise the handlers.
        $this->withoutMiddleware(ThrottleRequests::class);

        Queue::fake();

        foreach (self::TX_CONNECTIONS as $conn) {
            DB::connection($conn)->beginTransaction();
        }

        $this->userId = (string) Str::uuid();
        $this->propertyId = (string) Str::uuid();
        $this->listingId = (string) Str::uuid();
        $ownerId = (string) Str::uuid();

        // ── Identity: applicant + complete saved credentials ──────────────────
        DB::connection('identity')->table('users')->insert([
            'id' => $this->userId,
            'email' => "hunter-{$this->userId}@gmail.com",
            'password_hash' => Hash::make('Sup3rSecret!!'),
            'account_type' => 'hunter',
            'status' => 'active',
            'trust_score' => 75,
            'is_veteran' => false,
            'failed_login_attempts' => 0,
            'phone' => '(512) 555-0144',
        ]);
        DB::connection('identity')->table('user_profiles')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $this->userId,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'date_of_birth' => '1985-06-15',
            'state_code' => 'TX',
        ]);
        $this->writeCredentials();

        DB::connection('identity')->table('users')->insert([
            'id' => $ownerId,
            'email' => "owner-{$ownerId}@gmail.com",
            'password_hash' => 'test-hash',
            'status' => 'active',
            'account_type' => 'landowner',
        ]);

        // ── Platform: the certification the apply path records acceptance of ───
        DB::connection('platform')->table('legal_documents')->insert([
            'id' => (string) Str::uuid(),
            'document_key' => 'hunter_info_certification',
            'version' => 99,
            'title' => 'Test Certification',
            'content' => 'I certify the accuracy of the information provided.',
            'effective_date' => now()->toDateString(),
            'is_active' => true,
            'updated_at' => now(),
        ]);

        // ── Property: committed so property_read sees it ──────────────────────
        DB::connection('property')->table('properties')->insert([
            'id' => $this->propertyId,
            'owner_user_id' => $ownerId,
            'title' => 'Test Ranch',
            'slug' => 'test-ranch-'.substr($this->userId, 0, 8),
            'status' => 'active',
            'state_code' => 'TX',
            'county' => 'Travis',
            'total_acres' => '100.00',
        ]);
        DB::connection('property')->table('property_listings')->insert([
            'id' => $this->listingId,
            'property_id' => $this->propertyId,
            'listing_type' => 'annual_lease',
            'status' => 'active',
            'season_start' => '2026-10-01',
            'season_end' => '2026-11-30',
            'max_hunters' => 4,
            'price_per_hunter' => '500.00',
            'deposit_percent' => 25,
            'auto_renew' => false,
            'visibility' => 'public',
        ]);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse(self::TX_CONNECTIONS) as $conn) {
            try {
                DB::connection($conn)->rollBack();
            } catch (\Throwable) {
            }
        }

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

        Mockery::close();
        parent::tearDown();
    }

    // ── Fixture helpers ─────────────────────────────────────────────────────────

    /** Write a fully-populated, in-date credentials row for the applicant. */
    private function writeCredentials(array $overrides = []): void
    {
        DB::connection('identity')->table('hunter_credentials')->updateOrInsert(
            ['user_id' => $this->userId],
            array_merge([
                'id' => (string) Str::uuid(),
                'cell_phone' => '5125551234',
                'address_line1' => '123 Main St',
                'city' => 'Austin',
                'state_code' => 'TX',
                'zip_code' => '78701',
                'emergency_contact_name' => 'Jane Doe',
                'emergency_contact_phone' => '5125555678',
                'emergency_contact_relationship' => 'Spouse',
                'dl_number' => 'TX12345678',
                'dl_state' => 'TX',
                'dl_expiry' => '2028-01-01',
                'hunting_license_number' => 'TX-HL-9999',
                'hunting_license_state' => 'TX',
                'hunting_license_expiry' => '2027-01-01',
            ], $overrides),
        );
    }

    /** Insert a lease application row for the applicant directly. */
    private function makeApplication(array $overrides = []): string
    {
        $id = (string) Str::uuid();

        DB::connection('lease')->table('lease_applications')->insert(array_merge([
            'id' => $id,
            'listing_id' => $this->listingId,
            'applicant_user_id' => $this->userId,
            'application_type' => 'individual',
            'status' => 'pending',
            'property_title_snapshot' => 'Test Ranch',
        ], $overrides));

        return $id;
    }

    private function createListing(array $attributes): string
    {
        $id = (string) Str::uuid();
        DB::connection('property')->table('property_listings')->insert(array_merge([
            'id' => $id,
            'property_id' => $this->propertyId,
            'listing_type' => 'day_hunt',
            'status' => 'active',
            'season_start' => '2026-10-01',
            'season_end' => '2026-11-30',
            'max_hunters' => 4,
            'price_per_hunter' => '500.00',
            'deposit_percent' => 25,
            'auto_renew' => false,
            'visibility' => 'public',
        ], $attributes));
        $this->extraListingIds[] = $id;

        return $id;
    }

    private function tokenFor(string $userId, array $abilities = ['hunter:apply']): string
    {
        return User::findOrFail($userId)->createToken('test', $abilities)->plainTextToken;
    }

    private function as(string $userId, array $abilities = ['hunter:apply']): self
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($userId, $abilities));
    }

    private function applyPayload(array $overrides = []): array
    {
        return array_merge([
            'application_type' => 'individual',
            'message' => 'Looking forward to hunting on your property.',
            'certification_accepted' => true,
        ], $overrides);
    }

    // ── index ───────────────────────────────────────────────────────────────────

    public function test_index_lists_only_the_callers_applications_newest_first(): void
    {
        $this->makeApplication(['status' => 'pending']);
        $this->makeApplication(['status' => 'approved']);

        // Someone else's application must not leak into the caller's list.
        $this->makeApplication(['applicant_user_id' => (string) Str::uuid()]);

        $this->as($this->userId)->getJson('/api/v1/applications')
            ->assertStatus(200)
            ->assertJsonCount(2, 'applications')
            ->assertJsonPath('applications.0.property_title', 'Test Ranch');
    }

    // ── show ────────────────────────────────────────────────────────────────────

    public function test_show_returns_the_application_with_listing_and_booking_fee_keys(): void
    {
        $id = $this->makeApplication();

        $this->as($this->userId)->getJson("/api/v1/applications/{$id}")
            ->assertStatus(200)
            ->assertJsonPath('application.id', $id)
            ->assertJsonPath('listing.id', $this->listingId)
            ->assertJsonPath('property.state_code', 'TX')
            ->assertJsonStructure(['application', 'hunters', 'listing', 'property', 'lease', 'booking_fee']);
    }

    public function test_show_forbids_viewing_another_users_application(): void
    {
        $othersId = $this->makeApplication(['applicant_user_id' => (string) Str::uuid()]);

        $this->as($this->userId)->getJson("/api/v1/applications/{$othersId}")
            ->assertStatus(404);
    }

    // ── apply ───────────────────────────────────────────────────────────────────

    public function test_apply_creates_an_application_from_saved_credentials(): void
    {
        $this->as($this->userId)
            ->postJson("/api/v1/listings/{$this->listingId}/apply", $this->applyPayload())
            ->assertStatus(201)
            ->assertJsonPath('application.status', 'pending')
            ->assertJsonPath('application.application_type', 'individual');

        // Application + primary hunter snapshot persisted.
        $app = DB::connection('lease')->table('lease_applications')
            ->where('applicant_user_id', $this->userId)->first();
        $this->assertNotNull($app);
        // Fixed-term listing → term locked to the season regardless of client input.
        $this->assertSame('2026-10-01', substr((string) $app->proposed_start, 0, 10));

        $this->assertSame(1, DB::connection('lease')->table('lease_application_hunters')
            ->where('application_id', $app->id)->where('hunter_type', 'primary')->count());

        // Certification acceptance recorded.
        $this->assertDatabaseHas('user_legal_acceptances', [
            'user_id' => $this->userId,
            'document_key' => 'hunter_info_certification',
            'context_id' => $app->id,
        ], 'identity');
    }

    public function test_apply_is_blocked_by_a_duplicate_live_application(): void
    {
        $existing = $this->makeApplication(['status' => 'pending']);

        $this->as($this->userId)
            ->postJson("/api/v1/listings/{$this->listingId}/apply", $this->applyPayload())
            ->assertStatus(409)
            ->assertJsonPath('application_id', $existing);
    }

    public function test_apply_is_rejected_when_saved_credentials_are_incomplete(): void
    {
        // Wipe the license fields — the mobile client can't re-enter them.
        DB::connection('identity')->table('hunter_credentials')
            ->where('user_id', $this->userId)
            ->update(['hunting_license_number' => null, 'hunting_license_state' => null, 'hunting_license_expiry' => null]);

        $this->as($this->userId)
            ->postJson("/api/v1/listings/{$this->listingId}/apply", $this->applyPayload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('credentials');

        $this->assertSame(0, DB::connection('lease')->table('lease_applications')
            ->where('applicant_user_id', $this->userId)->count());
    }

    public function test_apply_is_rejected_when_license_state_does_not_match_the_property(): void
    {
        // Property is TX; a license issued by OK must be rejected before submit.
        $this->writeCredentials(['hunting_license_state' => 'OK']);

        $this->as($this->userId)
            ->postJson("/api/v1/listings/{$this->listingId}/apply", $this->applyPayload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('hunting_license_state');
    }

    public function test_apply_is_rejected_for_an_out_of_state_restricted_hunter(): void
    {
        // License matches the property (passes the licence gate), but the hunter's
        // single-state entitlement restricts them elsewhere — the submit-time gate
        // must surface as a 422 on the listing.
        $ent = Mockery::mock(EntitlementService::class)->makePartial();
        $ent->shouldReceive('canHuntInState')->andReturnFalse();
        $ent->shouldReceive('restrictedHuntState')->andReturn('OK');
        $this->instance(EntitlementService::class, $ent);

        $this->as($this->userId)
            ->postJson("/api/v1/listings/{$this->listingId}/apply", $this->applyPayload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('listing');

        $this->assertSame(0, DB::connection('lease')->table('lease_applications')
            ->where('applicant_user_id', $this->userId)->count());
    }

    public function test_apply_404s_for_an_inactive_listing(): void
    {
        $draftId = $this->createListing(['status' => 'draft']);

        $this->as($this->userId)
            ->postJson("/api/v1/listings/{$draftId}/apply", $this->applyPayload())
            ->assertStatus(404);
    }

    public function test_apply_requires_the_apply_ability(): void
    {
        $this->as($this->userId, ['hunter:read'])
            ->postJson("/api/v1/listings/{$this->listingId}/apply", $this->applyPayload())
            ->assertStatus(403);
    }

    public function test_apply_requires_authentication(): void
    {
        $this->postJson("/api/v1/listings/{$this->listingId}/apply", $this->applyPayload())
            ->assertStatus(401);
    }

    // ── withdraw ─────────────────────────────────────────────────────────────────

    public function test_withdraw_cancels_a_live_application(): void
    {
        $id = $this->makeApplication(['status' => 'pending']);

        $this->as($this->userId)->postJson("/api/v1/applications/{$id}/withdraw")
            ->assertStatus(200)
            ->assertJsonPath('application.status', 'withdrawn');

        $this->assertSame('withdrawn', DB::connection('lease')->table('lease_applications')
            ->where('id', $id)->value('status'));
    }

    public function test_withdraw_is_rejected_once_the_application_is_no_longer_live(): void
    {
        $id = $this->makeApplication(['status' => 'withdrawn']);

        $this->as($this->userId)->postJson("/api/v1/applications/{$id}/withdraw")
            ->assertStatus(422);
    }

    public function test_withdraw_forbids_touching_another_users_application(): void
    {
        $othersId = $this->makeApplication(['applicant_user_id' => (string) Str::uuid()]);

        $this->as($this->userId)->postJson("/api/v1/applications/{$othersId}/withdraw")
            ->assertStatus(404);
    }
}
