<?php

namespace Tests\Feature\Member;

use App\Models\Billing\SecurityDeposit;
use App\Services\Billing\StripeService;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Stripe\Checkout\Session;
use Tests\TestCase;

/**
 * Deposit success-return reconcile. Stripe redirects the lessee back to
 * /member/leases/{lease}/deposit/return?session_id=... immediately on payment;
 * the route (running as ah_system) retrieves the session and authors the held
 * row up front so the lease page shows "Held" without waiting on the webhook.
 *
 * Stripe is mocked; the deposit row is real on the billing connection (owner
 * role in tests bypasses RLS) and removed in tearDown.
 */
class DepositReturnTest extends TestCase
{
    private string $userId;
    private string $leaseId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        $this->userId = (string) Str::uuid();
        $this->leaseId = (string) Str::uuid();

        DB::connection('identity')->table('users')->insert([
            'id'            => $this->userId,
            'email'         => "lessee-{$this->userId}@test.invalid",
            'password_hash' => 'test-hash',
            'status'        => 'active',
            'account_type'  => 'hunter',
        ]);
    }

    protected function tearDown(): void
    {
        DB::connection('billing')->table('security_deposits')->where('lease_id', $this->leaseId)->delete();
        DB::connection('identity')->table('users')->where('id', $this->userId)->delete();

        parent::tearDown();
    }

    /** A completed deposit Checkout payload for the given lease. */
    private function sessionPayload(string $leaseId, string $pi): array
    {
        return [
            'mode'           => 'payment',
            'payment_intent' => $pi,
            'payment_status' => 'paid',
            'currency'       => 'usd',
            'amount_total'   => 50000,
            'metadata'       => [
                'purpose'       => 'security_deposit',
                'lease_id'      => $leaseId,
                'payer_user_id' => $this->userId,
                'payee_user_id' => (string) Str::uuid(),
                'amount_cents'  => '50000',
            ],
        ];
    }

    private function mockStripe(string $sessionId, array $payload): void
    {
        $stripe = Mockery::mock(StripeService::class);
        $stripe->shouldReceive('retrieveCheckoutSession')
            ->with($sessionId)
            ->andReturn(Session::constructFrom($payload));

        $this->app->instance(StripeService::class, $stripe);
    }

    public function test_return_reconciles_the_held_deposit_and_redirects(): void
    {
        $pi = 'pi_' . Str::random(14);
        $this->mockStripe('cs_match', $this->sessionPayload($this->leaseId, $pi));

        $this->withSession(['auth.user_id' => $this->userId])
            ->get("/member/leases/{$this->leaseId}/deposit/return?session_id=cs_match")
            ->assertRedirect(route('member.leases.show', ['lease' => $this->leaseId, 'deposit' => 'paid']));

        $deposit = SecurityDeposit::where('lease_id', $this->leaseId)->first();
        $this->assertNotNull($deposit, 'the held row should be authored on return');
        $this->assertSame('held', $deposit->status);
        $this->assertSame(50000, (int) $deposit->amount_cents);
        $this->assertSame($pi, $deposit->stripe_payment_intent_id);
    }

    public function test_return_ignores_a_session_for_a_different_lease(): void
    {
        // The session belongs to some other lease; reaching it through this lease's
        // return URL must not author a row here.
        $this->mockStripe('cs_other', $this->sessionPayload((string) Str::uuid(), 'pi_other'));

        $this->withSession(['auth.user_id' => $this->userId])
            ->get("/member/leases/{$this->leaseId}/deposit/return?session_id=cs_other")
            ->assertRedirect(route('member.leases.show', ['lease' => $this->leaseId, 'deposit' => 'paid']));

        $this->assertSame(0, SecurityDeposit::where('lease_id', $this->leaseId)->count());
    }

    public function test_return_without_a_session_id_just_redirects(): void
    {
        $this->withSession(['auth.user_id' => $this->userId])
            ->get("/member/leases/{$this->leaseId}/deposit/return")
            ->assertRedirect(route('member.leases.show', ['lease' => $this->leaseId, 'deposit' => 'paid']));

        $this->assertSame(0, SecurityDeposit::where('lease_id', $this->leaseId)->count());
    }
}
