<?php

namespace Tests\Feature\Platform;

use App\Models\Identity\User;
use App\Services\Platform\EntitlementService;
use App\Support\Entitlements;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * single_state_hunt locks a hunter to the FIRST residence state ever recorded
 * (user_profiles.original_state_code). The original state is captured once by a
 * DB trigger and is immutable, so editing the home state later cannot move the
 * restriction. Covers both the trigger and the EntitlementService gate.
 */
class SingleStateHuntTest extends TestCase
{
    private string $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (string) Str::uuid();
        DB::connection('identity')->table('users')->insert([
            'id'                => $this->userId,
            'email'             => 'single-state@hunt.test',
            'password_hash'     => bcrypt('Password1!local'),
            'status'            => 'active',
            'account_type'      => 'hunter',
            'email_verified_at' => now(),
            'trust_score'       => 100,
        ]);
    }

    protected function tearDown(): void
    {
        // Cascade removes the profile row too.
        DB::connection('identity')->table('users')->where('id', $this->userId)->delete();
        DB::connection('identity')->disconnect();
        parent::tearDown();
    }

    private function profileState(): ?string
    {
        return DB::connection('identity')->table('user_profiles')
            ->where('user_id', $this->userId)
            ->value('original_state_code');
    }

    public function test_trigger_captures_original_state_on_first_write(): void
    {
        DB::connection('identity')->table('user_profiles')->insert([
            'user_id'    => $this->userId,
            'state_code' => 'TX',
        ]);

        $this->assertSame('TX', $this->profileState());
    }

    public function test_original_state_is_immutable_after_home_state_change(): void
    {
        DB::connection('identity')->table('user_profiles')->insert([
            'user_id'    => $this->userId,
            'state_code' => 'TX',
        ]);

        // Hunter relocates on paper.
        DB::connection('identity')->table('user_profiles')
            ->where('user_id', $this->userId)
            ->update(['state_code' => 'OK']);

        $this->assertSame('TX', $this->profileState(), 'original_state_code must not follow a home-state change.');
    }

    public function test_original_state_captured_when_state_first_becomes_known(): void
    {
        // Profile created without a residence state.
        DB::connection('identity')->table('user_profiles')->insert([
            'user_id'    => $this->userId,
            'state_code' => null,
        ]);
        $this->assertNull($this->profileState());

        DB::connection('identity')->table('user_profiles')
            ->where('user_id', $this->userId)
            ->update(['state_code' => 'FL']);

        $this->assertSame('FL', $this->profileState());
    }

    public function test_restricted_hunt_state_returns_original_when_entitlement_on(): void
    {
        DB::connection('identity')->table('user_profiles')->insert([
            'user_id'    => $this->userId,
            'state_code' => 'TX',
        ]);

        $user = User::on('identity')->find($this->userId);

        $service = $this->serviceWithEntitlements([Entitlements::SINGLE_STATE_HUNT]);

        $this->assertSame('TX', $service->restrictedHuntState($user));
        $this->assertTrue($service->canHuntInState($user, 'tx'), 'case-insensitive match to allowed state');
        $this->assertFalse($service->canHuntInState($user, 'OK'), 'out-of-state hunt is disallowed');
    }

    public function test_unrestricted_hunter_may_hunt_anywhere(): void
    {
        DB::connection('identity')->table('user_profiles')->insert([
            'user_id'    => $this->userId,
            'state_code' => 'TX',
        ]);

        $user = User::on('identity')->find($this->userId);

        $service = $this->serviceWithEntitlements([]);

        $this->assertNull($service->restrictedHuntState($user));
        $this->assertTrue($service->canHuntInState($user, 'OK'));
    }

    public function test_multi_state_hunt_overrides_single_state_restriction(): void
    {
        DB::connection('identity')->table('user_profiles')->insert([
            'user_id'    => $this->userId,
            'state_code' => 'TX',
        ]);

        $user = User::on('identity')->find($this->userId);

        // A membership that grants BOTH: multi_state_hunt must win.
        $service = $this->serviceWithEntitlements([
            Entitlements::SINGLE_STATE_HUNT,
            Entitlements::MULTI_STATE_HUNT,
        ]);

        $this->assertNull($service->restrictedHuntState($user), 'multi_state_hunt lifts the single-state lock');
        $this->assertTrue($service->canHuntInState($user, 'OK'), 'may hunt out of the original state');
    }

    /**
     * A partial EntitlementService whose can() reports only the given keys enabled,
     * so restrictedHuntState() can be exercised without seeding plans/subscriptions.
     *
     * @param  string[]  $enabledKeys
     */
    private function serviceWithEntitlements(array $enabledKeys): EntitlementService
    {
        $service = Mockery::mock(EntitlementService::class)->makePartial();
        $service->shouldReceive('can')
            ->andReturnUsing(fn (User $u, string $key): bool => in_array($key, $enabledKeys, true));

        return $service;
    }
}
