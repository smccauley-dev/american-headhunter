<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Token issuance, expiry, abilities, and revocation.
 *
 * Isolation: users inserted directly into identity DB; cleaned up in tearDown.
 */
class AuthTokenTest extends TestCase
{
    private string $userId;
    private string $userEmail;
    private string $userPassword;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId       = (string) Str::uuid();
        $this->userEmail    = "hunter-token-test-{$this->userId}@example.com";
        $this->userPassword = 'TestPass123!';

        DB::connection('identity')->table('users')->insert([
            'id'           => $this->userId,
            'email'        => $this->userEmail,
            'password_hash' => Hash::make($this->userPassword),
            'account_type' => 'hunter',
            'status'       => 'active',
            'trust_score'  => 75,
            'is_veteran'   => false,
            'failed_login_attempts' => 0,
        ]);

        DB::connection('identity')->table('user_profiles')->insert([
            'id'         => (string) Str::uuid(),
            'user_id'    => $this->userId,
            'first_name' => 'Token',
            'last_name'  => 'Tester',
        ]);
    }

    protected function tearDown(): void
    {
        DB::connection('identity')->table('personal_access_tokens')
            ->where('tokenable_id', $this->userId)->delete();
        DB::connection('identity')->table('user_profiles')
            ->where('user_id', $this->userId)->delete();
        DB::connection('identity')->table('users')
            ->where('id', $this->userId)->delete();

        foreach ([
            'identity', 'property', 'property_read',
            'lease', 'billing', 'wildlife', 'wildlife_read',
            'commerce', 'communications',
            'incidents', 'documents', 'platform',
            'geospatial', 'geospatial_read',
        ] as $conn) {
            try { DB::connection($conn)->disconnect(); } catch (\Throwable) {}
        }

        parent::tearDown();
    }

    public function test_login_without_mfa_returns_token(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email'    => $this->userEmail,
            'password' => $this->userPassword,
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['token', 'user' => ['id', 'email']]);
        $response->assertJsonPath('user.id', $this->userId);
        $this->assertNotEmpty($response->json('token'));
    }

    public function test_login_with_wrong_password_returns_401(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email'    => $this->userEmail,
            'password' => 'wrong-password',
        ])->assertStatus(401);
    }

    public function test_login_with_unknown_email_returns_401(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email'    => 'nobody@example.com',
            'password' => 'whatever',
        ])->assertStatus(401);
    }

    public function test_issued_token_stored_on_identity_connection(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email'    => $this->userEmail,
            'password' => $this->userPassword,
        ])->assertStatus(200);

        $row = DB::connection('identity')->table('personal_access_tokens')
            ->where('tokenable_id', $this->userId)->first();

        $this->assertNotNull($row, 'Token row must exist in identity DB');
        $this->assertSame('App\\Models\\Identity\\User', $row->tokenable_type);
    }

    public function test_issued_token_expires_at_365_days(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email'    => $this->userEmail,
            'password' => $this->userPassword,
        ])->assertStatus(200);

        $row = DB::connection('identity')->table('personal_access_tokens')
            ->where('tokenable_id', $this->userId)->first();

        $this->assertNotNull($row->expires_at);

        $expiresAt = \Carbon\Carbon::parse($row->expires_at);

        $this->assertTrue(
            $expiresAt->between(now()->addDays(364), now()->addDays(366)),
            "expires_at ({$expiresAt}) should be ~365 days from now"
        );
    }

    public function test_issued_token_has_hunter_abilities_not_host_or_admin(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email'    => $this->userEmail,
            'password' => $this->userPassword,
        ])->assertStatus(200);

        $row = DB::connection('identity')->table('personal_access_tokens')
            ->where('tokenable_id', $this->userId)->first();

        $abilities = json_decode($row->abilities, true);

        $this->assertContains('hunter:read',    $abilities);
        $this->assertContains('hunter:apply',   $abilities);
        $this->assertContains('hunter:checkin', $abilities);

        $hostOrAdmin = array_filter(
            $abilities,
            fn ($a) => str_starts_with($a, 'host:') || str_starts_with($a, 'admin:')
        );
        $this->assertEmpty($hostOrAdmin, 'Hunter tokens must not carry host:* or admin:* abilities');
    }

    public function test_logout_revokes_current_token(): void
    {
        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'email'    => $this->userEmail,
            'password' => $this->userPassword,
        ]);
        $token = $loginResponse->json('token');

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertStatus(204);

        $remaining = DB::connection('identity')->table('personal_access_tokens')
            ->where('tokenable_id', $this->userId)->count();
        $this->assertSame(0, $remaining, 'Token must be deleted after logout');
    }

    public function test_revoke_all_deletes_every_token(): void
    {
        $r1 = $this->postJson('/api/v1/auth/login', [
            'email'    => $this->userEmail,
            'password' => $this->userPassword,
        ]);
        $token1 = $r1->json('token');
        DB::connection('identity')->disconnect();

        $this->postJson('/api/v1/auth/login', [
            'email'    => $this->userEmail,
            'password' => $this->userPassword,
        ]);
        DB::connection('identity')->disconnect();

        $count = DB::connection('identity')->table('personal_access_tokens')
            ->where('tokenable_id', $this->userId)->count();
        $this->assertSame(2, $count, 'Two tokens before revoke-all');

        $this->withToken($token1)->postJson('/api/v1/auth/revoke-all')->assertStatus(204);

        $remaining = DB::connection('identity')->table('personal_access_tokens')
            ->where('tokenable_id', $this->userId)->count();
        $this->assertSame(0, $remaining, 'All tokens gone after revoke-all');
    }

    public function test_unauthenticated_request_to_v1_properties_returns_401(): void
    {
        $this->getJson('/api/v1/properties/')->assertStatus(401);
    }

    public function test_valid_token_can_access_v1_properties(): void
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email'    => $this->userEmail,
            'password' => $this->userPassword,
        ])->json('token');

        $this->withToken($token)->getJson('/api/v1/properties/')->assertStatus(200);
    }

    public function test_legacy_api_properties_requires_no_auth(): void
    {
        $this->getJson('/api/properties/')->assertStatus(200);
    }
}
