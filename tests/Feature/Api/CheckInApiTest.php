<?php

namespace Tests\Feature\Api;

use App\Models\Identity\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase B mobile API — field check-in / check-out over CheckInService.
 *
 * Real rows on the identity + lease connections (tests run as owner → RLS
 * bypassed). Standing to check in is enforced in the service (403); the token
 * ability (hunter:checkin) gates the route.
 */
class CheckInApiTest extends TestCase
{
    private string $leaseId;

    private string $applicationId;

    private string $propertyId;

    private string $lesseeId;

    /** @var string[] identity user ids to clean up */
    private array $userIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        $this->applicationId = (string) Str::uuid();
        $this->leaseId = (string) Str::uuid();
        $this->propertyId = (string) Str::uuid();
        $this->lesseeId = $this->makeUser();

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
            'total_price' => '2500.00',
            'deposit_paid' => '0.00',
        ]);
    }

    protected function tearDown(): void
    {
        DB::connection('lease')->table('check_ins')->where('lease_id', $this->leaseId)->delete();
        DB::connection('lease')->table('leases')->where('id', $this->leaseId)->delete();
        DB::connection('lease')->table('lease_applications')->where('id', $this->applicationId)->delete();

        if ($this->userIds) {
            DB::connection('identity')->table('personal_access_tokens')
                ->whereIn('tokenable_id', $this->userIds)->delete();
            DB::connection('identity')->table('user_profiles')->whereIn('user_id', $this->userIds)->delete();
            DB::connection('identity')->table('users')->whereIn('id', $this->userIds)->delete();
        }

        DB::connection('lease')->disconnect();
        DB::connection('identity')->disconnect();

        parent::tearDown();
    }

    private function makeUser(): string
    {
        $id = (string) Str::uuid();

        DB::connection('identity')->table('users')->insert([
            'id' => $id,
            'email' => 'checkin-'.$id.'@gmail.com',
            'password_hash' => Hash::make('Sup3rSecret!!'),
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

        $this->userIds[] = $id;

        return $id;
    }

    private function tokenFor(string $userId, array $abilities = ['hunter:checkin']): string
    {
        return User::findOrFail($userId)->createToken('test', $abilities)->plainTextToken;
    }

    private function as(string $userId, array $abilities = ['hunter:checkin']): self
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($userId, $abilities));
    }

    public function test_active_is_null_when_nothing_open(): void
    {
        $this->as($this->lesseeId)->getJson('/api/v1/checkins/active')
            ->assertStatus(200)
            ->assertJsonPath('active', null);
    }

    public function test_check_in_then_active_reports_in_the_field(): void
    {
        $this->as($this->lesseeId)
            ->postJson("/api/v1/leases/{$this->leaseId}/checkin")
            ->assertStatus(201)
            ->assertJsonPath('new', true)
            ->assertJsonPath('check_in.lease_id', $this->leaseId)
            ->assertJsonPath('check_in.open', true);

        $this->as($this->lesseeId)->getJson('/api/v1/checkins/active')
            ->assertStatus(200)
            ->assertJsonPath('active.lease_id', $this->leaseId)
            ->assertJsonPath('active.open', true);
    }

    public function test_check_in_is_idempotent(): void
    {
        $this->as($this->lesseeId)->postJson("/api/v1/leases/{$this->leaseId}/checkin")->assertStatus(201);

        $this->as($this->lesseeId)->postJson("/api/v1/leases/{$this->leaseId}/checkin")
            ->assertStatus(200)
            ->assertJsonPath('new', false);

        $this->assertSame(1, DB::connection('lease')->table('check_ins')
            ->where('lease_id', $this->leaseId)->where('user_id', $this->lesseeId)->count());
    }

    public function test_check_out_closes_the_open_check_in(): void
    {
        $this->as($this->lesseeId)->postJson("/api/v1/leases/{$this->leaseId}/checkin")->assertStatus(201);

        $this->as($this->lesseeId)->postJson("/api/v1/leases/{$this->leaseId}/checkout")
            ->assertStatus(200)
            ->assertJsonPath('check_in.open', false);

        $this->as($this->lesseeId)->getJson('/api/v1/checkins/active')->assertJsonPath('active', null);
    }

    public function test_check_out_with_nothing_open_is_404(): void
    {
        $this->as($this->lesseeId)->postJson("/api/v1/leases/{$this->leaseId}/checkout")
            ->assertStatus(404);
    }

    public function test_a_user_without_standing_is_forbidden(): void
    {
        $stranger = $this->makeUser();

        $this->as($stranger)->postJson("/api/v1/leases/{$this->leaseId}/checkin")
            ->assertStatus(403);

        $this->assertSame(0, DB::connection('lease')->table('check_ins')
            ->where('lease_id', $this->leaseId)->count());
    }

    public function test_token_without_checkin_ability_is_forbidden(): void
    {
        $this->as($this->lesseeId, ['hunter:read'])
            ->postJson("/api/v1/leases/{$this->leaseId}/checkin")
            ->assertStatus(403);
    }

    public function test_check_in_requires_authentication(): void
    {
        $this->postJson("/api/v1/leases/{$this->leaseId}/checkin")->assertStatus(401);
    }
}
