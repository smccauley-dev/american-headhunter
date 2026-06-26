<?php

namespace Tests\Feature\Member;

use App\Services\Billing\PayoutService;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 5 (Connect Payouts, slice 2) — landowner Stripe Connect onboarding.
 *
 * PayoutService is mocked so the suite never calls Stripe; these assertions cover
 * the controller wiring: only landowners may onboard, and the connect action hands
 * the member off to the hosted Stripe onboarding URL. The account row creation and
 * fee/disbursement logic are covered by PayoutServiceTest.
 */
class PayoutOnboardingTest extends TestCase
{
    /** @var array<int,string> */ private array $userIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        // connect carries throttle:10,1 (Valkey-backed, persists across runs).
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    protected function tearDown(): void
    {
        if ($this->userIds) {
            DB::connection('identity')->table('users')->whereIn('id', $this->userIds)->delete();
        }
        parent::tearDown();
    }

    private function makeUser(string $accountType): string
    {
        $id = (string) Str::uuid();
        DB::connection('identity')->table('users')->insert([
            'id'            => $id,
            'email'         => "payout-{$id}@test.invalid",
            'password_hash' => 'test-hash',
            'status'        => 'active',
            'account_type'  => $accountType,
        ]);
        $this->userIds[] = $id;

        return $id;
    }

    public function test_landowner_is_handed_off_to_hosted_onboarding(): void
    {
        $userId = $this->makeUser('landowner');

        $this->mock(PayoutService::class, function ($mock) {
            $mock->shouldReceive('startOnboarding')
                ->once()
                ->andReturn('https://connect.stripe.test/onboard');
        });

        $response = $this->withSession(['auth.user_id' => $userId])
            ->withHeader('X-Inertia', 'true')
            ->post('/member/payouts/connect');

        $response->assertStatus(409);
        $response->assertHeader('X-Inertia-Location', 'https://connect.stripe.test/onboard');
    }

    public function test_non_landowner_cannot_onboard(): void
    {
        $userId = $this->makeUser('hunter');

        $this->mock(PayoutService::class, function ($mock) {
            $mock->shouldNotReceive('startOnboarding');
        });

        $response = $this->withSession(['auth.user_id' => $userId])
            ->post('/member/payouts/connect');

        $response->assertStatus(403);
    }
}
