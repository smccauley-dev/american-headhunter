<?php

namespace Tests\Feature\Api;

use App\Models\Identity\User;
use App\Services\Billing\PromotionAutoApplyService;
use App\Services\Identity\OfacService;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Phase A mobile API — registration, password recovery, and profile read/update.
 *
 * Isolation: registration writes real identity rows; every created user id is
 * tracked and hard-deleted in tearDown across the tables UserService touches.
 * Side-effecting collaborators (OFAC screen, signup promotions, the immutable
 * audit log via the verification job) are neutralized so a test run leaves no
 * permanent trace — Queue::fake() holds the verification job, and OFAC /
 * promotion are mocked to no-ops.
 */
class OnboardingApiTest extends TestCase
{
    /** @var string[] */
    private array $createdUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Inline throttle:N,1 keys its bucket by domain+IP (not per-route), so
        // every auth request in this class would share one bucket. Rate limiting
        // is covered by its own tests; disable it here to exercise the handlers.
        $this->withoutMiddleware(ThrottleRequests::class);

        Queue::fake();

        // OFAC screen must not reach the real provider or write side effects.
        $ofac = Mockery::mock(OfacService::class);
        $ofac->shouldReceive('screen')->andReturn('clear');
        $this->instance(OfacService::class, $ofac);

        // Signup promotions are best-effort in UserService::create; stub them out.
        $promos = Mockery::mock(PromotionAutoApplyService::class)->shouldIgnoreMissing();
        $this->instance(PromotionAutoApplyService::class, $promos);
    }

    protected function tearDown(): void
    {
        $ids = $this->createdUserIds;
        if ($ids) {
            DB::connection('identity')->table('personal_access_tokens')
                ->whereIn('tokenable_id', $ids)->delete();
            DB::connection('identity')->table('password_reset_tokens')->whereIn('user_id', $ids)->delete();
            DB::connection('identity')->table('user_roles')->whereIn('user_id', $ids)->delete();
            DB::connection('identity')->table('consent_log')->whereIn('user_id', $ids)->delete();
            DB::connection('identity')->table('user_profiles')->whereIn('user_id', $ids)->delete();
            DB::connection('identity')->table('users')->whereIn('id', $ids)->delete();
        }

        foreach (['identity'] as $conn) {
            try {
                DB::connection($conn)->disconnect();
            } catch (\Throwable) {
            }
        }

        Mockery::close();
        parent::tearDown();
    }

    /** Insert an active hunter directly and track it for cleanup. */
    private function makeUser(string $email, string $password): string
    {
        $id = (string) Str::uuid();

        DB::connection('identity')->table('users')->insert([
            'id' => $id,
            'email' => strtolower($email),
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
            'first_name' => 'Field',
            'last_name' => 'Tester',
        ]);

        $this->createdUserIds[] = $id;

        return $id;
    }

    private function tokenFor(string $userId): string
    {
        return User::findOrFail($userId)
            ->createToken('test', ['hunter:read', 'hunter:apply', 'hunter:checkin'])
            ->plainTextToken;
    }

    // ── Registration ──────────────────────────────────────────────────────────

    public function test_register_creates_account_and_issues_token(): void
    {
        $email = 'ah-reg-'.Str::uuid().'@gmail.com';

        $response = $this->postJson('/api/v1/auth/register', [
            'account_type' => 'hunter',
            'email' => $email,
            'password' => 'Sup3rSecret!!',
            'password_confirmation' => 'Sup3rSecret!!',
            'first_name' => 'Dale',
            'last_name' => 'Hunter',
            'date_of_birth' => '1990-04-02',
            'phone' => '(512) 555-0144',
            'state_code' => 'TX',
            'tos_accepted' => true,
            'privacy_accepted' => true,
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['token', 'user' => ['id', 'email', 'account_type'], 'intended_plan_key']);
        $response->assertJsonPath('user.email', strtolower($email));

        $userId = $response->json('user.id');
        $this->createdUserIds[] = $userId;

        // Persisted as a real user with a hashed token.
        $this->assertDatabaseHas('users', ['id' => $userId, 'account_type' => 'hunter'], 'identity');
        $this->assertSame(1, DB::connection('identity')->table('personal_access_tokens')
            ->where('tokenable_id', $userId)->count());
    }

    public function test_register_rejects_duplicate_email(): void
    {
        $email = 'ah-dup-'.Str::uuid().'@gmail.com';
        $this->makeUser($email, 'Sup3rSecret!!');

        $response = $this->postJson('/api/v1/auth/register', [
            'account_type' => 'hunter',
            'email' => $email,
            'password' => 'Sup3rSecret!!',
            'password_confirmation' => 'Sup3rSecret!!',
            'first_name' => 'Dale',
            'last_name' => 'Hunter',
            'date_of_birth' => '1990-04-02',
            'phone' => '(512) 555-0144',
            'state_code' => 'TX',
            'tos_accepted' => true,
            'privacy_accepted' => true,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
    }

    // ── Password recovery ─────────────────────────────────────────────────────

    public function test_forgot_password_returns_ok_and_does_not_leak_unknown_email(): void
    {
        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'nobody-'.Str::uuid().'@gmail.com'])
            ->assertStatus(200)
            ->assertJsonStructure(['message']);
    }

    public function test_reset_password_with_valid_token_changes_password(): void
    {
        $email = 'ah-reset-'.Str::uuid().'@gmail.com';
        $userId = $this->makeUser($email, 'OldPassw0rd!!');

        $plainToken = Str::random(64);
        DB::connection('identity')->table('password_reset_tokens')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'token_hash' => Hash::make($plainToken),
            'expires_at' => now()->addHour(),
            'ip_address' => '127.0.0.1',
        ]);

        $response = $this->postJson('/api/v1/auth/password/reset', [
            'email' => $email,
            'token' => $plainToken,
            'password' => 'BrandN3wPass!!',
            'password_confirmation' => 'BrandN3wPass!!',
        ]);

        $response->assertStatus(200);

        $hash = DB::connection('identity')->table('users')->where('id', $userId)->value('password_hash');
        $this->assertTrue(Hash::check('BrandN3wPass!!', $hash));
    }

    public function test_reset_password_rejects_invalid_token(): void
    {
        $email = 'ah-badtoken-'.Str::uuid().'@gmail.com';
        $userId = $this->makeUser($email, 'OldPassw0rd!!');

        DB::connection('identity')->table('password_reset_tokens')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'token_hash' => Hash::make(Str::random(64)),
            'expires_at' => now()->addHour(),
            'ip_address' => '127.0.0.1',
        ]);

        $this->postJson('/api/v1/auth/password/reset', [
            'email' => $email,
            'token' => 'wrong-token',
            'password' => 'BrandN3wPass!!',
            'password_confirmation' => 'BrandN3wPass!!',
        ])->assertStatus(422);
    }

    // ── Profile ───────────────────────────────────────────────────────────────

    public function test_profile_requires_authentication(): void
    {
        $this->getJson('/api/v1/profile')->assertStatus(401);
    }

    public function test_profile_show_returns_current_user(): void
    {
        $email = 'ah-prof-'.Str::uuid().'@gmail.com';
        $userId = $this->makeUser($email, 'Sup3rSecret!!');
        $token = $this->tokenFor($userId);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/profile')
            ->assertStatus(200)
            ->assertJsonPath('id', $userId)
            ->assertJsonPath('email', strtolower($email))
            ->assertJsonPath('profile.first_name', 'Field');
    }

    public function test_profile_update_persists_core_fields(): void
    {
        $email = 'ah-upd-'.Str::uuid().'@gmail.com';
        $userId = $this->makeUser($email, 'Sup3rSecret!!');
        $token = $this->tokenFor($userId);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/v1/profile', [
                'first_name' => 'Dale',
                'last_name' => 'Earnhardt',
                'display_name' => 'The Intimidator',
                'bio' => 'Whitetail all season.',
                'state_code' => 'NC',
                'phone' => '(704) 555-0088',
                'hunting' => ['species' => ['whitetail_deer'], 'years_hunting' => 20],
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('profile.first_name', 'Dale');
        $response->assertJsonPath('profile.display_name', 'The Intimidator');

        $profile = DB::connection('identity')->table('user_profiles')->where('user_id', $userId)->first();
        $this->assertSame('NC', $profile->state_code);
        $this->assertSame('Earnhardt', $profile->last_name);
        $this->assertSame('(704) 555-0088', DB::connection('identity')
            ->table('users')->where('id', $userId)->value('phone'));
    }
}
