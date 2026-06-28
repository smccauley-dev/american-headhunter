<?php

namespace Tests\Feature\Member;

use App\Models\Billing\LeasePayment;
use App\Services\Billing\LeasePaymentService;
use App\Services\Billing\StripeService;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Stripe\Checkout\Session;
use Tests\TestCase;

/**
 * Member "Pay lease balance" route + the Stripe success-return reconcile.
 *
 * LeasePaymentService is mocked so the route is exercised without a Stripe round
 * trip or a listing/Connect fixture — the destination-charge math is proven in
 * LeasePaymentServiceTest. The lease + lessee are real. The POST redirects the
 * member to hosted Checkout; the db.system GET return reconciles the collected row.
 */
class PayLeaseBalanceTest extends TestCase
{
    private string $userId;
    private string $lessorUserId;
    private string $leaseId;
    private string $applicationId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        $this->userId        = (string) Str::uuid();
        $this->lessorUserId  = (string) Str::uuid();
        $this->leaseId       = (string) Str::uuid();
        $this->applicationId = (string) Str::uuid();

        DB::connection('identity')->table('users')->insert([
            'id'            => $this->userId,
            'email'         => "lessee-paybal-{$this->userId}@test.invalid",
            'password_hash' => 'test-hash',
            'status'        => 'active',
            'account_type'  => 'hunter',
        ]);

        DB::connection('lease')->table('lease_applications')->insert([
            'id'                => $this->applicationId,
            'listing_id'        => (string) Str::uuid(),
            'applicant_user_id' => $this->userId,
            'application_type'  => 'individual',
            'status'            => 'approved',
        ]);

        DB::connection('lease')->table('leases')->insert([
            'id'             => $this->leaseId,
            'application_id' => $this->applicationId,
            'property_id'    => (string) Str::uuid(),
            'listing_id'     => (string) Str::uuid(),
            'lessee_user_id' => $this->userId,
            'lessor_user_id' => $this->lessorUserId,
            'status'         => 'active',
            'start_date'     => '2026-10-01',
            'end_date'       => '2026-11-30',
            'total_price'    => '2500.00',
            'deposit_paid'   => '0.00',
        ]);
    }

    protected function tearDown(): void
    {
        DB::connection('lease')->table('leases')->where('id', $this->leaseId)->delete();
        DB::connection('lease')->table('lease_applications')->where('id', $this->applicationId)->delete();
        DB::connection('identity')->table('users')->where('id', $this->userId)->delete();
        parent::tearDown();
    }

    public function test_pay_route_redirects_to_hosted_checkout(): void
    {
        $this->mock(LeasePaymentService::class, function ($mock) {
            $mock->shouldReceive('createCheckoutSession')
                ->once()
                ->andReturn(Session::constructFrom([
                    'id'  => 'cs_lease_pay',
                    'url' => 'https://checkout.stripe.test/lease',
                ]));
        });

        $response = $this->withSession(['auth.user_id' => $this->userId])
            ->withHeader('X-Inertia', 'true')
            ->post("/member/leases/{$this->leaseId}/lease-payment");

        $response->assertStatus(409);
        $response->assertHeader('X-Inertia-Location', 'https://checkout.stripe.test/lease');
    }

    public function test_pay_route_surfaces_the_landowner_setup_error(): void
    {
        $this->mock(LeasePaymentService::class, function ($mock) {
            $mock->shouldReceive('createCheckoutSession')
                ->once()
                ->andThrow(new \RuntimeException('The landowner has not finished payout setup, so the lease balance cannot be paid yet.'));
        });

        $this->withSession(['auth.user_id' => $this->userId])
            ->post("/member/leases/{$this->leaseId}/lease-payment")
            ->assertSessionHasErrors('lease_payment');
    }

    public function test_return_reconciles_the_collected_payment(): void
    {
        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldReceive('retrieveCheckoutSession')
                ->once()
                ->with('cs_done')
                ->andReturn(Session::constructFrom([
                    'id'       => 'cs_done',
                    'metadata' => ['lease_id' => $this->leaseId, 'purpose' => 'lease_payment'],
                ]));
        });

        $this->mock(LeasePaymentService::class, function ($mock) {
            $mock->shouldReceive('recordCollectedFromCheckout')
                ->once()
                ->andReturn(new LeasePayment(['lease_id' => $this->leaseId, 'status' => 'collected']));
        });

        $this->withSession(['auth.user_id' => $this->userId])
            ->get("/member/leases/{$this->leaseId}/lease-payment/return?session_id=cs_done")
            ->assertRedirect(route('member.leases.show', ['lease' => $this->leaseId, 'payment' => 'paid']));
    }
}
