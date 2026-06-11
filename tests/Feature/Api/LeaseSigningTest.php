<?php

namespace Tests\Feature\Api;

use App\Services\Lease\DropboxSignService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Mobile lease signing API — GET /api/v1/leases/*
 *
 * Isolation: DB inserts in setUp, deleted in tearDown.
 * The user logs in via the API to obtain a real Sanctum token.
 */
class LeaseSigningTest extends TestCase
{
    private string $userId;
    private string $lessorUserId;
    private string $bearerToken;

    private string $applicationId;
    private string $leaseId;
    private string $esigRequestId;
    private string $esigSignerId;

    private string $propertyId;
    private string $listingId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId       = (string) Str::uuid();
        $this->lessorUserId = (string) Str::uuid();
        $this->propertyId   = (string) Str::uuid();
        $this->listingId    = (string) Str::uuid();
        $this->applicationId = (string) Str::uuid();
        $this->leaseId      = (string) Str::uuid();
        $this->esigRequestId = (string) Str::uuid();
        $this->esigSignerId  = (string) Str::uuid();

        $password = 'LeaseTest123!';

        // Identity fixtures
        DB::connection('identity')->table('users')->insert([
            'id'                    => $this->userId,
            'email'                 => "lessee-signing-{$this->userId}@example.com",
            'password_hash'         => Hash::make($password),
            'account_type'          => 'hunter',
            'status'                => 'active',
            'trust_score'           => 75,
            'is_veteran'            => false,
            'failed_login_attempts' => 0,
        ]);
        DB::connection('identity')->table('user_profiles')->insert([
            'id'         => (string) Str::uuid(),
            'user_id'    => $this->userId,
            'first_name' => 'Lessee',
            'last_name'  => 'Signer',
        ]);

        // Lease DB fixtures — application first (FK target), then lease
        DB::connection('lease')->table('lease_applications')->insert([
            'id'                => $this->applicationId,
            'listing_id'        => $this->listingId,
            'applicant_user_id' => $this->userId,
            'application_type'  => 'individual',
            'status'            => 'approved',
        ]);

        DB::connection('lease')->table('leases')->insert([
            'id'             => $this->leaseId,
            'application_id' => $this->applicationId,
            'property_id'    => $this->propertyId,
            'listing_id'     => $this->listingId,
            'lessee_user_id' => $this->userId,
            'lessor_user_id' => $this->lessorUserId,
            'status'         => 'pending_signatures',
            'start_date'     => '2026-10-01',
            'end_date'       => '2026-11-30',
            'total_price'    => '2500.00',
            'deposit_paid'   => '0.00',
        ]);

        // Documents DB fixtures — in-platform esig request
        DB::connection('documents')->table('esignature_requests')->insert([
            'id'               => $this->esigRequestId,
            'lease_id'         => $this->leaseId,
            'requester_user_id' => $this->lessorUserId,
            'provider'         => 'in_platform',
            'status'           => 'out_for_signature',
            'subject'          => 'Hunting Lease Agreement — 2026',
            'requested_at'     => now(),
        ]);

        DB::connection('documents')->table('esignature_signers')->insert([
            'id'         => $this->esigSignerId,
            'request_id' => $this->esigRequestId,
            'user_id'    => $this->userId,
            'email'      => "lessee-signing-{$this->userId}@example.com",
            'name'       => 'Lessee Signer',
            'order_num'  => 2,
            'status'     => 'pending',
        ]);

        // Login to get bearer token
        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'email'    => "lessee-signing-{$this->userId}@example.com",
            'password' => $password,
        ]);
        $this->bearerToken = $loginResponse->json('token');
    }

    protected function tearDown(): void
    {
        DB::connection('documents')->table('esignature_signers')->where('id', $this->esigSignerId)->delete();
        DB::connection('documents')->table('esignature_requests')->where('id', $this->esigRequestId)->delete();

        DB::connection('lease')->table('leases')->where('id', $this->leaseId)->delete();
        DB::connection('lease')->table('lease_applications')->where('id', $this->applicationId)->delete();

        DB::connection('identity')->table('personal_access_tokens')->where('tokenable_id', $this->userId)->delete();
        DB::connection('identity')->table('user_profiles')->where('user_id', $this->userId)->delete();
        DB::connection('identity')->table('users')->where('id', $this->userId)->delete();

        foreach (['identity', 'lease', 'documents'] as $conn) {
            try { DB::connection($conn)->disconnect(); } catch (\Throwable) {}
        }

        parent::tearDown();
    }

    // ── Auth guard ────────────────────────────────────────────────────────────

    public function test_lease_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/leases')->assertStatus(401);
    }

    public function test_lease_show_requires_authentication(): void
    {
        $this->getJson("/api/v1/leases/{$this->leaseId}")->assertStatus(401);
    }

    // ── GET /api/v1/leases ────────────────────────────────────────────────────

    public function test_index_returns_leases_where_user_is_lessee(): void
    {
        $response = $this->withToken($this->bearerToken)
            ->getJson('/api/v1/leases');

        $response->assertStatus(200);
        $response->assertJsonStructure(['data' => [['id', 'status', 'start_date', 'end_date', 'total_price', 'role']]]);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertContains($this->leaseId, $ids->toArray());

        $lease = collect($response->json('data'))->firstWhere('id', $this->leaseId);
        $this->assertSame('lessee', $lease['role']);
        $this->assertSame('pending_signatures', $lease['status']);
    }

    public function test_index_does_not_return_leases_for_unrelated_user(): void
    {
        // Create a second user and a lease they're not party to
        $otherId  = (string) Str::uuid();
        $otherApp = (string) Str::uuid();
        $otherLease = (string) Str::uuid();

        DB::connection('lease')->table('lease_applications')->insert([
            'id'                => $otherApp,
            'listing_id'        => (string) Str::uuid(),
            'applicant_user_id' => $otherId,
        ]);
        DB::connection('lease')->table('leases')->insert([
            'id'             => $otherLease,
            'application_id' => $otherApp,
            'property_id'    => (string) Str::uuid(),
            'listing_id'     => (string) Str::uuid(),
            'lessee_user_id' => $otherId,
            'lessor_user_id' => (string) Str::uuid(),
            'status'         => 'active',
            'start_date'     => '2026-10-01',
            'end_date'       => '2026-11-30',
            'total_price'    => '1000.00',
            'deposit_paid'   => '0.00',
        ]);

        try {
            $response = $this->withToken($this->bearerToken)->getJson('/api/v1/leases');
            $ids = collect($response->json('data'))->pluck('id');
            $this->assertNotContains($otherLease, $ids->toArray());
        } finally {
            DB::connection('lease')->table('leases')->where('id', $otherLease)->delete();
            DB::connection('lease')->table('lease_applications')->where('id', $otherApp)->delete();
        }
    }

    // ── GET /api/v1/leases/{id} ───────────────────────────────────────────────

    public function test_show_returns_lease_detail_with_signing_status(): void
    {
        $response = $this->withToken($this->bearerToken)
            ->getJson("/api/v1/leases/{$this->leaseId}");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'id', 'status', 'role', 'signing_provider', 'signing_status',
                'my_signature', 'signers',
            ],
        ]);
        $response->assertJsonPath('data.id', $this->leaseId);
        $response->assertJsonPath('data.role', 'lessee');
        $response->assertJsonPath('data.signing_provider', 'in_platform');
        $response->assertJsonPath('data.my_signature.status', 'pending');
    }

    public function test_show_returns_404_for_lease_user_is_not_party_to(): void
    {
        $otherId    = (string) Str::uuid();
        $otherApp   = (string) Str::uuid();
        $otherLease = (string) Str::uuid();

        DB::connection('lease')->table('lease_applications')->insert([
            'id'                => $otherApp,
            'listing_id'        => (string) Str::uuid(),
            'applicant_user_id' => $otherId,
        ]);
        DB::connection('lease')->table('leases')->insert([
            'id'             => $otherLease,
            'application_id' => $otherApp,
            'property_id'    => (string) Str::uuid(),
            'listing_id'     => (string) Str::uuid(),
            'lessee_user_id' => $otherId,
            'lessor_user_id' => (string) Str::uuid(),
            'status'         => 'active',
            'start_date'     => '2026-10-01',
            'end_date'       => '2026-11-30',
            'total_price'    => '1000.00',
            'deposit_paid'   => '0.00',
        ]);

        try {
            $this->withToken($this->bearerToken)
                ->getJson("/api/v1/leases/{$otherLease}")
                ->assertStatus(404);
        } finally {
            DB::connection('lease')->table('leases')->where('id', $otherLease)->delete();
            DB::connection('lease')->table('lease_applications')->where('id', $otherApp)->delete();
        }
    }

    // ── GET /api/v1/leases/{id}/signature-status ──────────────────────────────

    public function test_signature_status_returns_correct_structure(): void
    {
        $response = $this->withToken($this->bearerToken)
            ->getJson("/api/v1/leases/{$this->leaseId}/signature-status");

        $response->assertStatus(200);
        $response->assertJsonStructure(['data' => ['lease_status', 'signing_status', 'my_status', 'completed_at']]);
        $response->assertJsonPath('data.lease_status', 'pending_signatures');
        $response->assertJsonPath('data.signing_status', 'out_for_signature');
        $response->assertJsonPath('data.my_status', 'pending');
        $this->assertNull($response->json('data.completed_at'));
    }

    public function test_signature_status_returns_404_for_non_party(): void
    {
        $this->withToken($this->bearerToken)
            ->getJson('/api/v1/leases/' . Str::uuid() . '/signature-status')
            ->assertStatus(404);
    }

    // ── GET /api/v1/leases/{id}/contract ─────────────────────────────────────

    public function test_contract_returns_404_when_no_signed_document_exists(): void
    {
        $this->withToken($this->bearerToken)
            ->getJson("/api/v1/leases/{$this->leaseId}/contract")
            ->assertStatus(404);
    }

    // ── GET /api/v1/leases/{id}/signing-url ──────────────────────────────────

    public function test_signing_url_returns_422_for_in_platform_lease(): void
    {
        // In-platform provider — no Dropbox Sign request exists → 422
        $this->withToken($this->bearerToken)
            ->getJson("/api/v1/leases/{$this->leaseId}/signing-url")
            ->assertStatus(422);
    }

    public function test_signing_url_returns_url_for_dropbox_sign_signer(): void
    {
        $dsbRequestId = 'hr_' . Str::random(20);
        $dsbSignerId  = 'sig_' . Str::random(20);
        $esigId       = (string) Str::uuid();
        $sigId        = (string) Str::uuid();
        $fakeSignUrl  = 'https://app.hellosign.com/sign/embedded?token=abc123';

        // Add a Dropbox Sign request for the same lease
        DB::connection('documents')->table('esignature_requests')->insert([
            'id'                            => $esigId,
            'lease_id'                      => $this->leaseId,
            'requester_user_id'             => $this->lessorUserId,
            'provider'                      => 'dropbox_sign',
            'provider_signature_request_id' => $dsbRequestId,
            'status'                        => 'out_for_signature',
            'subject'                       => 'Test',
            'requested_at'                  => now(),
        ]);
        DB::connection('documents')->table('esignature_signers')->insert([
            'id'                 => $sigId,
            'request_id'         => $esigId,
            'user_id'            => $this->userId,
            'email'              => "lessee-signing-{$this->userId}@example.com",
            'name'               => 'Lessee Signer',
            'order_num'          => 2,
            'status'             => 'pending',
            'provider_signer_id' => $dsbSignerId,
        ]);

        // Mock DropboxSignService so no real HTTP call is made
        $this->mock(DropboxSignService::class, function ($mock) use ($dsbSignerId, $fakeSignUrl) {
            $mock->shouldReceive('getEmbeddedSigningUrl')
                ->once()
                ->with($dsbSignerId)
                ->andReturn($fakeSignUrl);
        });

        try {
            $response = $this->withToken($this->bearerToken)
                ->getJson("/api/v1/leases/{$this->leaseId}/signing-url");

            $response->assertStatus(200);
            $response->assertJsonStructure(['data' => ['signing_url', 'expires_in']]);
            $response->assertJsonPath('data.signing_url', $fakeSignUrl);
            $response->assertJsonPath('data.expires_in', 3600);
        } finally {
            DB::connection('documents')->table('esignature_signers')->where('id', $sigId)->delete();
            DB::connection('documents')->table('esignature_requests')->where('id', $esigId)->delete();
        }
    }
}
