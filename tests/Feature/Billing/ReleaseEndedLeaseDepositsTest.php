<?php

namespace Tests\Feature\Billing;

use App\Jobs\Billing\ReleaseEndedLeaseDeposits;
use App\Services\Billing\SecurityDepositService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * The daily auto-release sweep returns a still-held deposit only when its lease
 * ended a normal way more than the grace window ago. SecurityDepositService is a
 * spy — the real release()/Stripe refund is covered by SecurityDepositServiceTest;
 * here we only assert WHICH deposits the job decides to release. The leases and
 * deposit rows are real (owner role in tests bypasses RLS).
 */
class ReleaseEndedLeaseDepositsTest extends TestCase
{
    /** @var array<int,string> */ private array $leaseIds = [];
    /** @var array<int,string> */ private array $applicationIds = [];
    /** @var array<int,string> */ private array $depositIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Past the job's Stripe-config guard; no real Stripe call (service is a spy).
        config(['services.stripe.secret' => 'sk_test_dummy']);
    }

    protected function tearDown(): void
    {
        if ($this->depositIds) {
            DB::connection('billing')->table('security_deposits')->whereIn('id', $this->depositIds)->delete();
        }
        if ($this->leaseIds) {
            DB::connection('lease')->table('leases')->whereIn('id', $this->leaseIds)->delete();
        }
        if ($this->applicationIds) {
            DB::connection('lease')->table('lease_applications')->whereIn('id', $this->applicationIds)->delete();
        }
        parent::tearDown();
    }

    /** Seed a lease in $status ending on $endDate; returns its id. */
    private function seedLease(string $status, string $endDate): string
    {
        $applicationId = (string) Str::uuid();
        DB::connection('lease')->table('lease_applications')->insert([
            'id'                => $applicationId,
            'listing_id'        => (string) Str::uuid(),
            'applicant_user_id' => (string) Str::uuid(),
            'application_type'  => 'individual',
            'status'            => 'approved',
        ]);
        $this->applicationIds[] = $applicationId;

        $id = (string) Str::uuid();
        DB::connection('lease')->table('leases')->insert([
            'id'             => $id,
            'application_id' => $applicationId,
            'property_id'    => (string) Str::uuid(),
            'listing_id'     => (string) Str::uuid(),
            'lessee_user_id' => (string) Str::uuid(),
            'lessor_user_id' => (string) Str::uuid(),
            'status'         => $status,
            'start_date'     => '2025-10-01',
            'end_date'       => $endDate,
            'total_price'    => '2500.00',
            'deposit_paid'   => '500.00',
        ]);
        $this->leaseIds[] = $id;

        return $id;
    }

    /** Seed a held deposit on $leaseId; returns its id. */
    private function seedHeldDeposit(string $leaseId): string
    {
        $id = (string) Str::uuid();
        DB::connection('billing')->table('security_deposits')->insert([
            'id'                       => $id,
            'lease_id'                 => $leaseId,
            'payer_user_id'            => (string) Str::uuid(),
            'payee_user_id'            => (string) Str::uuid(),
            'amount_cents'             => 50000,
            'currency'                 => 'USD',
            'status'                   => 'held',
            'stripe_payment_intent_id' => 'pi_' . Str::random(14),
            'held_at'                  => now(),
        ]);
        $this->depositIds[] = $id;

        return $id;
    }

    /** @return \Mockery\MockInterface&SecurityDepositService */
    private function depositServiceSpy()
    {
        $spy = Mockery::spy(SecurityDepositService::class);
        $this->app->instance(SecurityDepositService::class, $spy);

        return $spy;
    }

    public function test_releases_only_normally_ended_leases_past_the_grace_window(): void
    {
        // Eligible: lease ended > 14 days ago, normal end (expired / active-past-end).
        $eligibleExpired = $this->seedHeldDeposit($this->seedLease('expired', now()->subDays(30)->toDateString()));
        $eligibleActive  = $this->seedHeldDeposit($this->seedLease('active',  now()->subDays(20)->toDateString()));

        // Ineligible.
        $withinGrace = $this->seedHeldDeposit($this->seedLease('expired',    now()->subDays(5)->toDateString()));
        $terminated  = $this->seedHeldDeposit($this->seedLease('terminated', now()->subDays(60)->toDateString()));
        $cancelled   = $this->seedHeldDeposit($this->seedLease('cancelled',  now()->subDays(60)->toDateString()));
        $futureEnd   = $this->seedHeldDeposit($this->seedLease('active',     now()->addDays(10)->toDateString()));

        $spy = $this->depositServiceSpy();

        (new ReleaseEndedLeaseDeposits(14))->handle($spy);

        $spy->shouldHaveReceived('release')->with($eligibleExpired, null, Mockery::type('string'))->once();
        $spy->shouldHaveReceived('release')->with($eligibleActive,  null, Mockery::type('string'))->once();

        foreach ([$withinGrace, $terminated, $cancelled, $futureEnd] as $skipId) {
            $spy->shouldNotHaveReceived('release', [$skipId, Mockery::any(), Mockery::any()]);
        }
    }

    public function test_skips_everything_when_stripe_is_not_configured(): void
    {
        config(['services.stripe.secret' => null]);

        $eligible = $this->seedHeldDeposit($this->seedLease('expired', now()->subDays(30)->toDateString()));

        $spy = $this->depositServiceSpy();

        (new ReleaseEndedLeaseDeposits(14))->handle($spy);

        $spy->shouldNotHaveReceived('release', [$eligible, Mockery::any(), Mockery::any()]);
    }
}
