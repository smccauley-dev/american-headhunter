<?php

namespace Tests\Feature\Api;

use App\Services\Platform\MfaFactorService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers:
 *   - SMS defaults to disabled out of the box (seed)
 *   - Platform-disabled factor rejects new enrollment
 *   - Existing enrollment on a disabled factor still passes verification (Option A)
 *   - Enabling a disabled factor allows enrollment again
 *
 * Isolation: mfa_factor_settings rows are modified and restored in setUp/tearDown.
 * The service cache is cleared before each test to avoid stale enabled-state.
 */
class MfaFactorSettingTest extends TestCase
{
    private string $userId;
    private array  $originalSettings = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Snapshot seeded factor settings so tearDown can restore them.
        $rows = DB::connection('platform')
            ->table('mfa_factor_settings')
            ->get(['factor', 'is_enabled']);

        foreach ($rows as $row) {
            $this->originalSettings[$row->factor] = (bool) $row->is_enabled;
        }

        // Bust cached factor state so tests see current DB values.
        foreach (['totp', 'sms', 'email'] as $factor) {
            Cache::store('valkey')->forget("mfa_factor_enabled:{$factor}");
        }

        // Create a minimal user for enrollment tests.
        $this->userId = (string) Str::uuid();
        DB::connection('identity')->table('users')->insert([
            'id'           => $this->userId,
            'email'        => "factortest-{$this->userId}@example.com",
            'password_hash' => bcrypt('secret'),
            'account_type' => 'hunter',
            'status'       => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        // Restore factor settings to seed state.
        foreach ($this->originalSettings as $factor => $wasEnabled) {
            DB::connection('platform')
                ->table('mfa_factor_settings')
                ->where('factor', $factor)
                ->update(['is_enabled' => $wasEnabled]);
        }

        // Clear cache after restoring so next run sees clean state.
        foreach (['totp', 'sms', 'email'] as $factor) {
            Cache::store('valkey')->forget("mfa_factor_enabled:{$factor}");
        }

        DB::connection('identity')->table('users')->where('id', $this->userId)->delete();

        foreach ([
            'identity', 'platform',
        ] as $conn) {
            try { DB::connection($conn)->disconnect(); } catch (\Throwable) {}
        }

        parent::tearDown();
    }

    // ── Seed defaults ─────────────────────────────────────────────────────────

    public function test_sms_defaults_to_disabled(): void
    {
        $service = app(MfaFactorService::class);
        $this->assertFalse($service->isFactorEnabled('sms'));
    }

    public function test_totp_and_email_default_to_enabled(): void
    {
        $service = app(MfaFactorService::class);
        $this->assertTrue($service->isFactorEnabled('totp'));
        $this->assertTrue($service->isFactorEnabled('email'));
    }

    // ── Enrollment gate ───────────────────────────────────────────────────────

    public function test_enrolling_disabled_sms_returns_422(): void
    {
        $token = $this->mintHunterToken($this->userId);

        $response = $this->withToken($token)
            ->postJson('/api/v1/mfa/enroll/sms');

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'This MFA method is not currently available.']);
    }

    public function test_enrolling_totp_works_while_sms_is_disabled(): void
    {
        $token = $this->mintHunterToken($this->userId);

        // TOTP enroll returns a secret + QR URL even though SMS is disabled.
        $response = $this->withToken($token)
            ->postJson('/api/v1/mfa/enroll/totp');

        $response->assertStatus(200);
        $response->assertJsonStructure(['method', 'secret', 'qr_code_url']);
    }

    public function test_disabling_totp_blocks_new_enrollment(): void
    {
        // Disable TOTP platform-wide.
        DB::connection('platform')
            ->table('mfa_factor_settings')
            ->where('factor', 'totp')
            ->update(['is_enabled' => false]);

        Cache::store('valkey')->forget('mfa_factor_enabled:totp');

        $token = $this->mintHunterToken($this->userId);

        $response = $this->withToken($token)
            ->postJson('/api/v1/mfa/enroll/totp');

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'This MFA method is not currently available.']);
    }

    // ── Option A: existing enrollment survives platform disable ───────────────

    public function test_existing_totp_enrollment_can_still_verify_when_totp_is_platform_disabled(): void
    {
        // Seed an already-enrolled (and verified) TOTP row for this user.
        // We bypass the API enroll flow since we just want the DB state.
        DB::connection('identity')->table('mfa_configurations')->insert([
            'id'          => (string) Str::uuid(),
            'user_id'     => $this->userId,
            'method'      => 'totp',
            'is_enabled'  => true,
            'verified_at' => now(),
        ]);

        // Now disable TOTP platform-wide.
        DB::connection('platform')
            ->table('mfa_factor_settings')
            ->where('factor', 'totp')
            ->update(['is_enabled' => false]);

        Cache::store('valkey')->forget('mfa_factor_enabled:totp');

        // Verification path (mfaVerify) must NOT gate on isFactorEnabled — Option A.
        // We test this by confirming the verify endpoint is reachable (returns 422 for bad code,
        // not 422 with "not available" message, proving the factor gate is not in the verify path).
        $challengeToken = (string) Str::uuid();
        Cache::store('sessions')->put(
            "mfa_challenge:{$challengeToken}",
            ['user_id' => $this->userId, 'methods' => ['totp']],
            now()->addMinutes(5)
        );

        $response = $this->postJson('/api/v1/auth/mfa/verify', [
            'challenge_token' => $challengeToken,
            'method'          => 'totp',
            'code'            => '000000',
        ]);

        // 422 with "Invalid" = verify ran and rejected a bad code (not gated by factor setting).
        $response->assertStatus(422);
        $this->assertStringContainsString(
            'Invalid',
            $response->json('message'),
            'Verify should fail with bad code, not with factor-disabled message'
        );
    }

    // ── Re-enable clears gate ─────────────────────────────────────────────────

    public function test_re_enabling_sms_allows_enrollment(): void
    {
        // Enable SMS (it defaults to false).
        DB::connection('platform')
            ->table('mfa_factor_settings')
            ->where('factor', 'sms')
            ->update(['is_enabled' => true]);

        Cache::store('valkey')->forget('mfa_factor_enabled:sms');

        // Give the user a phone number so the enroll flow can reach triggerChallenge.
        DB::connection('identity')->table('users')
            ->where('id', $this->userId)
            ->update(['phone' => '+15005550006']);

        $token = $this->mintHunterToken($this->userId);

        $response = $this->withToken($token)
            ->postJson('/api/v1/mfa/enroll/sms');

        // Returns 200 with sent_to mask — enrollment is open.
        $response->assertStatus(200);
        $response->assertJsonStructure(['method', 'sent_to']);
    }

    // ── MfaFactorService cache invalidation ───────────────────────────────────

    public function test_service_cache_reflects_db_change_after_invalidation(): void
    {
        $service = app(MfaFactorService::class);

        // totp starts enabled.
        $this->assertTrue($service->isFactorEnabled('totp'));

        // Update DB.
        DB::connection('platform')
            ->table('mfa_factor_settings')
            ->where('factor', 'totp')
            ->update(['is_enabled' => false]);

        // Without cache bust, the cached value is still true.
        $this->assertTrue($service->isFactorEnabled('totp'));

        // Invalidate the cache key.
        $service->invalidateFactor('totp');

        // Now the service reads the updated DB value.
        $this->assertFalse($service->isFactorEnabled('totp'));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function mintHunterToken(string $userId): string
    {
        $user = \App\Models\Identity\User::find($userId);

        /** @var \App\Models\Identity\PersonalAccessToken $pat */
        $pat = $user->createToken('test-device', ['hunter:read']);

        return $pat->plainTextToken;
    }
}
