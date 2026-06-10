<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * MFA-gated login: password -> challenge token -> MFA verify -> full PAT.
 *
 * Uses TOTP (no external service required).
 */
class MfaLoginTest extends TestCase
{
    private string $userId;
    private string $userEmail;
    private string $userPassword;
    private string $totpSecret;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId       = (string) Str::uuid();
        $this->userEmail    = "hunter-mfa-test-{$this->userId}@example.com";
        $this->userPassword = 'MfaPass456!';

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
            'first_name' => 'Mfa',
            'last_name'  => 'Tester',
        ]);

        $google2fa        = new Google2FA();
        $this->totpSecret = $google2fa->generateSecretKey();
        $encKey           = config('database.connections.identity.options.encryption_key');

        DB::connection('identity')->statement(
            "INSERT INTO mfa_configurations (id, user_id, method, is_enabled, secret_encrypted, verified_at)
             VALUES (gen_random_uuid(), ?, 'totp', true, pgp_sym_encrypt(?, ?), NOW())",
            [$this->userId, $this->totpSecret, $encKey]
        );
    }

    protected function tearDown(): void
    {
        DB::connection('identity')->table('mfa_configurations')
            ->where('user_id', $this->userId)->delete();
        DB::connection('identity')->table('mfa_challenges')
            ->where('user_id', $this->userId)->delete();
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

    private function loginAndGetChallengeToken(): string
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email'    => $this->userEmail,
            'password' => $this->userPassword,
        ]);
        $response->assertStatus(200);
        return $response->json('challenge_token');
    }

    private function currentTotpCode(): string
    {
        return (new Google2FA())->getCurrentOtp($this->totpSecret);
    }

    public function test_login_returns_challenge_token_not_full_token(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email'    => $this->userEmail,
            'password' => $this->userPassword,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('mfa_required', true);
        $response->assertJsonStructure(['challenge_token', 'mfa_methods']);
        $response->assertJsonPath('mfa_methods', ['totp']);

        // Sanctum PATs contain '|' — a challenge token must not
        $this->assertStringNotContainsString('|', $response->json('challenge_token'));

        $count = DB::connection('identity')->table('personal_access_tokens')
            ->where('tokenable_id', $this->userId)->count();
        $this->assertSame(0, $count, 'No PAT before MFA verification');
    }

    public function test_challenge_token_cannot_be_used_as_bearer(): void
    {
        $challengeToken = $this->loginAndGetChallengeToken();

        $this->withToken($challengeToken)
            ->getJson('/api/v1/properties/')
            ->assertStatus(401);
    }

    public function test_mfa_verify_with_correct_totp_issues_full_token(): void
    {
        $challengeToken = $this->loginAndGetChallengeToken();

        $response = $this->postJson('/api/v1/auth/mfa/verify', [
            'challenge_token' => $challengeToken,
            'method'          => 'totp',
            'code'            => $this->currentTotpCode(),
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['token', 'user' => ['id', 'email']]);
        $response->assertJsonPath('user.id', $this->userId);
        $this->assertStringContainsString('|', $response->json('token'));
    }

    public function test_full_token_grants_access_to_v1_endpoints(): void
    {
        $challengeToken = $this->loginAndGetChallengeToken();

        $token = $this->postJson('/api/v1/auth/mfa/verify', [
            'challenge_token' => $challengeToken,
            'method'          => 'totp',
            'code'            => $this->currentTotpCode(),
        ])->json('token');

        $this->withToken($token)->getJson('/api/v1/properties/')->assertStatus(200);
    }

    public function test_wrong_code_returns_422_and_no_token_issued(): void
    {
        $challengeToken = $this->loginAndGetChallengeToken();

        $this->postJson('/api/v1/auth/mfa/verify', [
            'challenge_token' => $challengeToken,
            'method'          => 'totp',
            'code'            => '000000',
        ])->assertStatus(422);

        $count = DB::connection('identity')->table('personal_access_tokens')
            ->where('tokenable_id', $this->userId)->count();
        $this->assertSame(0, $count, 'No token after failed MFA');
    }

    public function test_wrong_code_does_not_consume_challenge_token(): void
    {
        $challengeToken = $this->loginAndGetChallengeToken();

        // Fail first
        $this->postJson('/api/v1/auth/mfa/verify', [
            'challenge_token' => $challengeToken,
            'method'          => 'totp',
            'code'            => '000000',
        ])->assertStatus(422);

        // Same challenge token succeeds with valid code
        $this->postJson('/api/v1/auth/mfa/verify', [
            'challenge_token' => $challengeToken,
            'method'          => 'totp',
            'code'            => $this->currentTotpCode(),
        ])->assertStatus(200);
    }

    public function test_unknown_challenge_token_returns_422(): void
    {
        $this->postJson('/api/v1/auth/mfa/verify', [
            'challenge_token' => (string) Str::uuid(),
            'method'          => 'totp',
            'code'            => '123456',
        ])->assertStatus(422);
    }

    public function test_recovery_codes_returned_at_first_enrollment(): void
    {
        // Fresh user with no MFA enrolled
        $freshId    = (string) Str::uuid();
        $freshEmail = "fresh-{$freshId}@example.com";

        DB::connection('identity')->table('users')->insert([
            'id'           => $freshId,
            'email'        => $freshEmail,
            'password_hash' => Hash::make('FreshPass789!'),
            'account_type' => 'hunter',
            'status'       => 'active',
            'trust_score'  => 75,
            'is_veteran'   => false,
            'failed_login_attempts' => 0,
        ]);

        // Login — no MFA, get token directly
        $token = $this->postJson('/api/v1/auth/login', [
            'email'    => $freshEmail,
            'password' => 'FreshPass789!',
        ])->json('token');

        // Enroll TOTP
        $enrollResponse = $this->withToken($token)->postJson('/api/v1/mfa/enroll/totp');
        $enrollResponse->assertStatus(200);
        $newSecret = $enrollResponse->json('secret');

        // Confirm
        $confirmResponse = $this->withToken($token)->postJson('/api/v1/mfa/confirm/totp', [
            'code' => (new Google2FA())->getCurrentOtp($newSecret),
        ]);
        $confirmResponse->assertStatus(200);
        $confirmResponse->assertJsonPath('verified', true);

        $codes = $confirmResponse->json('recovery_codes');
        $this->assertIsArray($codes);
        $this->assertCount(10, $codes);

        // Cleanup
        DB::connection('identity')->table('mfa_configurations')
            ->where('user_id', $freshId)->delete();
        DB::connection('identity')->table('personal_access_tokens')
            ->where('tokenable_id', $freshId)->delete();
        DB::connection('identity')->table('users')
            ->where('id', $freshId)->delete();
    }
}
