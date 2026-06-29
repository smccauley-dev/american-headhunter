<?php

namespace Tests\Feature\Member;

use App\Models\Billing\SecurityDeposit;
use App\Services\Billing\SecurityDepositService;
use App\Services\Billing\StripeService;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Stripe\Refund;
use Tests\TestCase;

/**
 * The landowner self-service deposit routes (db.system): the lessor can release the
 * held deposit back to the hunter, or file a hunter-fault forfeiture claim (which only
 * records a claim — the money stays held and the hunter can contest it). Neither is
 * available to anyone but the lease's lessor. Lease + deposit rows are real (owner role
 * in tests bypasses RLS); Stripe is mocked.
 */
class LandownerDepositActionsTest extends TestCase
{
    private string $hunterId;
    private string $landownerId;
    private string $leaseId;
    private string $applicationId;
    private string $depositId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        $this->hunterId      = (string) Str::uuid();
        $this->landownerId   = (string) Str::uuid();
        $this->leaseId       = (string) Str::uuid();
        $this->applicationId = (string) Str::uuid();

        foreach ([[$this->hunterId, 'hunter'], [$this->landownerId, 'landowner']] as [$id, $type]) {
            DB::connection('identity')->table('users')->insert([
                'id'            => $id,
                'email'         => "deposit-{$type}-{$id}@test.invalid",
                'password_hash' => 'test-hash',
                'status'        => 'active',
                'account_type'  => $type,
                'trust_score'   => 80,
            ]);
            DB::connection('identity')->table('user_profiles')->insert([
                'id'         => (string) Str::uuid(),
                'user_id'    => $id,
                'first_name' => 'Deposit',
                'last_name'  => 'Test ' . ucfirst($type),
            ]);
        }

        DB::connection('lease')->table('lease_applications')->insert([
            'id'                => $this->applicationId,
            'listing_id'        => (string) Str::uuid(),
            'applicant_user_id' => $this->hunterId,
            'application_type'  => 'individual',
            'status'            => 'approved',
        ]);

        DB::connection('lease')->table('leases')->insert([
            'id'             => $this->leaseId,
            'application_id' => $this->applicationId,
            'property_id'    => (string) Str::uuid(),
            'listing_id'     => (string) Str::uuid(),
            'lessee_user_id' => $this->hunterId,
            'lessor_user_id' => $this->landownerId,
            'status'         => 'terminated',
            'start_date'     => '2026-10-01',
            'end_date'       => '2026-11-30',
            'total_price'    => '2500.00',
            'deposit_paid'   => '500.00',
        ]);

        $deposit = SecurityDeposit::create([
            'lease_id'                 => $this->leaseId,
            'payer_user_id'            => $this->hunterId,
            'payee_user_id'            => $this->landownerId,
            'amount_cents'             => 50000,
            'currency'                 => 'USD',
            'status'                   => 'held',
            'stripe_payment_intent_id' => 'pi_' . Str::random(12),
            'held_at'                  => now(),
        ]);
        $this->depositId = $deposit->id;
    }

    protected function tearDown(): void
    {
        DB::connection('billing')->table('security_deposits')->where('id', $this->depositId)->delete();
        DB::connection('lease')->table('leases')->where('id', $this->leaseId)->delete();
        DB::connection('lease')->table('lease_applications')->where('id', $this->applicationId)->delete();
        DB::connection('identity')->table('user_profiles')->whereIn('user_id', [$this->hunterId, $this->landownerId])->delete();
        DB::connection('identity')->table('users')->whereIn('id', [$this->hunterId, $this->landownerId])->delete();

        parent::tearDown();
    }

    public function test_landowner_can_release_the_held_deposit(): void
    {
        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldReceive('refundPaymentIntent')
                ->once()
                ->with(Mockery::any(), 50000, Mockery::any())
                ->andReturn(Refund::constructFrom(['id' => 're_release']));
        });

        $this->withSession(['auth.user_id' => $this->landownerId])
            ->post("/member/leases/{$this->leaseId}/deposit/release")
            ->assertRedirect()
            ->assertSessionHas('success');

        $deposit = SecurityDeposit::find($this->depositId);
        $this->assertSame('released', $deposit->status);
        $this->assertSame(50000, $deposit->refunded_amount_cents);
    }

    public function test_landowner_can_file_a_forfeiture_claim_without_moving_money(): void
    {
        // FAULT_LESSEE records a claim only — no refund is issued.
        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldNotReceive('refundPaymentIntent');
        });

        $this->withSession(['auth.user_id' => $this->landownerId])
            ->post("/member/leases/{$this->leaseId}/deposit/forfeit", [
                'amount'   => '350.00',
                'reason'   => 'Damaged the cabin door.',
                'category' => 'property_damage',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $deposit = SecurityDeposit::find($this->depositId);
        $this->assertSame('held', $deposit->status, 'a claim must not move the money');
        $this->assertSame('pending', $deposit->forfeit_trust_status);
        $this->assertSame(SecurityDepositService::FAULT_LESSEE, $deposit->forfeit_fault);
        $this->assertSame(35000, $deposit->forfeited_amount_cents);
    }

    public function test_release_is_forbidden_for_the_hunter(): void
    {
        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldNotReceive('refundPaymentIntent');
        });

        $this->withSession(['auth.user_id' => $this->hunterId])
            ->post("/member/leases/{$this->leaseId}/deposit/release")
            ->assertNotFound();

        $this->assertSame('held', SecurityDeposit::find($this->depositId)->status);
    }

    public function test_forfeit_is_forbidden_for_a_stranger(): void
    {
        $strangerId = (string) Str::uuid();
        DB::connection('identity')->table('users')->insert([
            'id' => $strangerId, 'email' => "stranger-{$strangerId}@test.invalid",
            'password_hash' => 'test-hash', 'status' => 'active', 'account_type' => 'landowner',
        ]);
        DB::connection('identity')->table('user_profiles')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $strangerId,
            'first_name' => 'Stranger', 'last_name' => 'Test',
        ]);

        $this->withSession(['auth.user_id' => $strangerId])
            ->post("/member/leases/{$this->leaseId}/deposit/forfeit", [
                'amount' => '100.00', 'reason' => 'Not my lease.',
            ])
            ->assertNotFound();

        $deposit = SecurityDeposit::find($this->depositId);
        $this->assertNull($deposit->forfeit_fault, 'a stranger must not be able to file a claim');

        DB::connection('identity')->table('user_profiles')->where('user_id', $strangerId)->delete();
        DB::connection('identity')->table('users')->where('id', $strangerId)->delete();
    }
}
