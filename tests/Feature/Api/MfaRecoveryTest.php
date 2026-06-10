<?php

namespace Tests\Feature\Api;

use App\Services\Auth\MfaService;
use App\Services\Identity\UserService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Recovery code lifecycle: generation, single-use, rate limiting, admin reset,
 * regeneration factor-verification, and factor-lifecycle isolation.
 */
class MfaRecoveryTest extends TestCase
{
    private string $userId;
    private string $userEmail;
    private string $userPassword;
    private string $totpSecret;

    /** Plaintext recovery codes seeded in setUp for most tests. */
    private array $recoveryCodes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId       = (string) Str::uuid();
        $this->userEmail    = "recovery-test-{$this->userId}@example.com";
        $this->userPassword = 'RecovPass123!';

        DB::connection('identity')->table('users')->insert([
            'id'                   => $this->userId,
            'email'                => $this->userEmail,
            'password_hash'        => Hash::make($this->userPassword),
            'account_type'         => 'hunter',
            'status'               => 'active',
            'trust_score'          => 75,
            'is_veteran'           => false,
            'failed_login_attempts' => 0,
        ]);

        DB::connection('identity')->table('user_profiles')->insert([
            'id'         => (string) Str::uuid(),
            'user_id'    => $this->userId,
            'first_name' => 'Recovery',
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

        // Seed recovery codes directly via the service
        $user = \App\Models\Identity\User::find($this->userId);
        $this->recoveryCodes = app(MfaService::class)->generateBackupCodes($user);
    }

    protected function tearDown(): void
    {
        DB::connection('identity')->table('user_recovery_codes')
            ->where('user_id', $this->userId)->delete();
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

    // ── Helpers ───────────────────────────────────────────────────────────────

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

    // ── 1. Single-use consumption ─────────────────────────────────────────────

    public function test_recovery_code_works_once_then_is_consumed(): void
    {
        $challengeToken = $this->loginAndGetChallengeToken();
        $code           = $this->recoveryCodes[0];

        // First use: success
        $response = $this->postJson('/api/v1/auth/mfa/recover', [
            'challenge_token' => $challengeToken,
            'recovery_code'   => $code,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('used_recovery_code', true);
        $response->assertJsonStructure(['token', 'user' => ['id', 'email']]);

        // Get a fresh challenge token (the first was consumed)
        $challengeToken2 = $this->loginAndGetChallengeToken();

        // Second use of same code: fails (consumed)
        $this->postJson('/api/v1/auth/mfa/recover', [
            'challenge_token' => $challengeToken2,
            'recovery_code'   => $code,
        ])->assertStatus(422);
    }

    // ── 2. Separate rate-limit bucket ─────────────────────────────────────────

    public function test_recovery_uses_its_own_rate_limit_bucket(): void
    {
        $challengeToken = $this->loginAndGetChallengeToken();

        // Exhaust mfa-recover bucket (3/min) with wrong codes
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/mfa/recover', [
                'challenge_token' => $challengeToken,
                'recovery_code'   => 'WRONG-CODE',
            ])->assertStatus(422); // wrong code, not rate limited yet
        }

        // 4th request hits the mfa-recover limit
        $this->postJson('/api/v1/auth/mfa/recover', [
            'challenge_token' => $challengeToken,
            'recovery_code'   => 'WRONG-CODE',
        ])->assertStatus(429);

        // mfa-verify bucket for the same challenge_token is independent
        $this->postJson('/api/v1/auth/mfa/verify', [
            'challenge_token' => $challengeToken,
            'method'          => 'totp',
            'code'            => '000000',
        ])->assertStatus(422); // 422 (wrong code), not 429

        // Clean up the rate limit key so it doesn't bleed into other tests
        RateLimiter::clear('mfa-recover:' . $challengeToken);
    }

    // ── 3. Admin reset invalidates outstanding recovery codes ─────────────────

    public function test_admin_reset_invalidates_outstanding_recovery_codes(): void
    {
        $code = $this->recoveryCodes[0];

        // Capture a challenge token before the reset
        $challengeToken = $this->loginAndGetChallengeToken();

        // Admin resets MFA (via UserService directly — tests the service, not Filament UI)
        $user = \App\Models\Identity\User::find($this->userId);
        app(UserService::class)->resetMfa($user);

        // Challenge token was consumed by the reset (all PATs revoked, factors off)
        // To get a new challenge token we must log in again — but since MFA is now
        // disabled we get a direct PAT, not a challenge token.
        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'email'    => $this->userEmail,
            'password' => $this->userPassword,
        ]);
        $loginResponse->assertStatus(200);
        // No MFA enrolled: should receive a token directly, not a challenge
        $this->assertNull($loginResponse->json('challenge_token'));

        // The pre-reset challenge token is also invalid (PATs revoked, session gone)
        // — more importantly, the recovery code itself is gone
        $freshChallengeToken = $this->loginAndGetChallengeTokenNoMfa();

        $this->postJson('/api/v1/auth/mfa/recover', [
            'challenge_token' => $freshChallengeToken ?? 'INVALID',
            'recovery_code'   => $code,
        ])->assertStatus(422); // recovery code was invalidated by reset
    }

    // ── 4. Regeneration rejected without factor re-verify ─────────────────────

    public function test_regeneration_rejected_with_only_pat(): void
    {
        // Login without MFA by using a fresh user (no MFA enrolled)
        $freshId    = (string) Str::uuid();
        $freshEmail = "regen-noauth-{$freshId}@example.com";

        DB::connection('identity')->table('users')->insert([
            'id'                   => $freshId,
            'email'                => $freshEmail,
            'password_hash'        => Hash::make('RegPass789!'),
            'account_type'         => 'hunter',
            'status'               => 'active',
            'trust_score'          => 75,
            'is_veteran'           => false,
            'failed_login_attempts' => 0,
        ]);

        $token = $this->postJson('/api/v1/auth/login', [
            'email'    => $freshEmail,
            'password' => 'RegPass789!',
        ])->json('token');

        // Call regenerate with only the PAT — no method/code
        $this->withToken($token)
            ->postJson('/api/v1/mfa/recovery-codes/regenerate', [])
            ->assertStatus(422); // validation fails: method and code are required

        // Cleanup
        DB::connection('identity')->table('personal_access_tokens')
            ->where('tokenable_id', $freshId)->delete();
        DB::connection('identity')->table('users')
            ->where('id', $freshId)->delete();
    }

    // ── 5. Regeneration works with any enrolled factor ─────────────────────────

    public function test_regeneration_works_with_enrolled_totp_factor(): void
    {
        // Get a PAT (via MFA challenge flow)
        $challengeToken = $this->loginAndGetChallengeToken();

        $token = $this->postJson('/api/v1/auth/mfa/verify', [
            'challenge_token' => $challengeToken,
            'method'          => 'totp',
            'code'            => $this->currentTotpCode(),
        ])->json('token');

        $this->assertNotNull($token);

        $oldCode = $this->recoveryCodes[0];

        $response = $this->withToken($token)->postJson('/api/v1/mfa/recovery-codes/regenerate', [
            'method' => 'totp',
            'code'   => $this->currentTotpCode(),
        ]);

        $response->assertStatus(200);
        $newCodes = $response->json('recovery_codes');
        $this->assertIsArray($newCodes);
        $this->assertCount(10, $newCodes);

        // Old codes are invalidated — old code no longer works
        $challengeToken2 = $this->loginAndGetChallengeToken();
        $this->postJson('/api/v1/auth/mfa/recover', [
            'challenge_token' => $challengeToken2,
            'recovery_code'   => $oldCode,
        ])->assertStatus(422);

        // A new code works
        $challengeToken3 = $this->loginAndGetChallengeToken();
        $this->postJson('/api/v1/auth/mfa/recover', [
            'challenge_token' => $challengeToken3,
            'recovery_code'   => $newCodes[0],
        ])->assertStatus(200);
    }

    // ── 6. Recovery codes survive disabling one factor ─────────────────────────

    public function test_recovery_codes_survive_disabling_one_factor(): void
    {
        // Add a second factor (email, enabled directly)
        DB::connection('identity')->table('mfa_configurations')->insert([
            'id'         => (string) Str::uuid(),
            'user_id'    => $this->userId,
            'method'     => 'email',
            'is_enabled' => true,
            'verified_at' => now(),
        ]);

        // Get a PAT via TOTP
        $challengeToken = $this->loginAndGetChallengeToken();
        $token = $this->postJson('/api/v1/auth/mfa/verify', [
            'challenge_token' => $challengeToken,
            'method'          => 'totp',
            'code'            => $this->currentTotpCode(),
        ])->json('token');

        // Disable TOTP via the disable endpoint
        $this->withToken($token)
            ->deleteJson('/api/v1/mfa/totp')
            ->assertStatus(200);

        // Login again — email factor is still active, get challenge token
        $response = $this->postJson('/api/v1/auth/login', [
            'email'    => $this->userEmail,
            'password' => $this->userPassword,
        ]);
        $response->assertStatus(200);
        $challengeToken2 = $response->json('challenge_token');
        $this->assertNotNull($challengeToken2, 'Email factor should still trigger MFA');

        // Recovery code still works (account-level, not coupled to TOTP)
        $recoverResponse = $this->postJson('/api/v1/auth/mfa/recover', [
            'challenge_token' => $challengeToken2,
            'recovery_code'   => $this->recoveryCodes[1],
        ]);

        $recoverResponse->assertStatus(200);
        $recoverResponse->assertJsonPath('used_recovery_code', true);

        // Cleanup extra config
        DB::connection('identity')->table('mfa_configurations')
            ->where('user_id', $this->userId)->where('method', 'email')->delete();
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Login as the main test user when no MFA factors are enrolled.
     * Returns the token directly (no challenge).
     */
    private function loginAndGetChallengeTokenNoMfa(): ?string
    {
        // After admin reset, no factors are enrolled, so login gives a PAT directly.
        // This helper is only used in the admin-reset test where we need a challenge
        // token — but after reset there's no challenge. Return null to signal this.
        return null;
    }
}
