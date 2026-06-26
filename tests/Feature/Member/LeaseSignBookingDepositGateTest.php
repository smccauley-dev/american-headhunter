<?php

namespace Tests\Feature\Member;

use App\Models\Billing\BookingDeposit;
use App\Services\Billing\BookingDepositService;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Pay-then-sign gate for the non-refundable booking deposit (LeaseSignController::
 * sign). Like the security deposit, the lessee's signature activates the lease, so
 * when the listing requires a booking deposit the lessee cannot sign until it is
 * collected. Proves the server-side enforcement — the Sign page UI only mirrors it.
 *
 * BookingDepositService is mocked so the gate is exercised without a Stripe round
 * trip or a listing fixture; SecurityDepositService is left real (no listing → no
 * security deposit due), so only the booking gate is under test. The lease, esign
 * request and signers are real.
 */
class LeaseSignBookingDepositGateTest extends TestCase
{
    private string $userId;
    private string $lessorUserId;
    private string $leaseId;
    private string $applicationId;
    private string $esigRequestId;
    private string $lesseeSignerId;
    private string $lessorSignerId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        $this->userId         = (string) Str::uuid();
        $this->lessorUserId   = (string) Str::uuid();
        $this->leaseId        = (string) Str::uuid();
        $this->applicationId  = (string) Str::uuid();
        $this->esigRequestId  = (string) Str::uuid();
        $this->lesseeSignerId = (string) Str::uuid();
        $this->lessorSignerId = (string) Str::uuid();

        DB::connection('identity')->table('users')->insert([
            'id'            => $this->userId,
            'email'         => "lessee-bgate-{$this->userId}@test.invalid",
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
            'status'         => 'pending_signatures',
            'start_date'     => '2026-10-01',
            'end_date'       => '2026-11-30',
            'total_price'    => '2500.00',
            'deposit_paid'   => '0.00',
        ]);

        DB::connection('documents')->table('esignature_requests')->insert([
            'id'                => $this->esigRequestId,
            'lease_id'          => $this->leaseId,
            'requester_user_id' => $this->lessorUserId,
            'provider'          => 'in_platform',
            'status'            => 'out_for_signature',
            'subject'           => 'Hunting Lease Agreement',
            'requested_at'      => now(),
        ]);

        // Lessor (order 1) left pending so the lessee's signature never completes
        // the request — keeps activation machinery out of this test.
        DB::connection('documents')->table('esignature_signers')->insert([
            'id'         => $this->lessorSignerId,
            'request_id' => $this->esigRequestId,
            'user_id'    => $this->lessorUserId,
            'email'      => "lessor-{$this->lessorUserId}@test.invalid",
            'name'       => 'Lessor Owner',
            'order_num'  => 1,
            'status'     => 'pending',
        ]);
        DB::connection('documents')->table('esignature_signers')->insert([
            'id'         => $this->lesseeSignerId,
            'request_id' => $this->esigRequestId,
            'user_id'    => $this->userId,
            'email'      => "lessee-bgate-{$this->userId}@test.invalid",
            'name'       => 'Lessee Hunter',
            'order_num'  => 2,
            'status'     => 'pending',
        ]);
    }

    protected function tearDown(): void
    {
        DB::connection('lease')->table('signature_events')->where('lease_id', $this->leaseId)->delete();
        DB::connection('documents')->table('esignature_signers')->where('request_id', $this->esigRequestId)->delete();
        DB::connection('documents')->table('esignature_requests')->where('id', $this->esigRequestId)->delete();
        DB::connection('lease')->table('leases')->where('id', $this->leaseId)->delete();
        DB::connection('lease')->table('lease_applications')->where('id', $this->applicationId)->delete();
        DB::connection('identity')->table('users')->where('id', $this->userId)->delete();

        parent::tearDown();
    }

    /** Bind a booking-deposit service reporting a $300 deposit due, paid or not. */
    private function mockBookingDeposit(bool $paid): void
    {
        $mock = Mockery::mock(BookingDepositService::class);
        $mock->shouldReceive('amountDueCents')->andReturn(30000);
        $mock->shouldReceive('forLease')->andReturn(
            $paid ? new BookingDeposit(['status' => 'collected', 'amount_cents' => 30000]) : null,
        );

        $this->app->instance(BookingDepositService::class, $mock);
    }

    private function lesseeSignerStatus(): string
    {
        return (string) DB::connection('documents')->table('esignature_signers')
            ->where('id', $this->lesseeSignerId)->value('status');
    }

    public function test_lessee_cannot_sign_until_the_booking_deposit_is_collected(): void
    {
        $this->mockBookingDeposit(paid: false);

        $this->withSession(['auth.user_id' => $this->userId])
            ->post("/member/leases/{$this->leaseId}/sign", [
                'request_id' => $this->esigRequestId,
                'full_name'  => 'Lessee Hunter',
                'agreed'     => true,
            ])
            ->assertRedirect(route('member.leases.sign', $this->leaseId))
            ->assertSessionHas('error');

        $this->assertSame('pending', $this->lesseeSignerStatus(), 'signature must not be recorded while the booking deposit is unpaid');
    }

    public function test_lessee_can_sign_once_the_booking_deposit_is_collected(): void
    {
        $this->mockBookingDeposit(paid: true);

        $this->withSession(['auth.user_id' => $this->userId])
            ->post("/member/leases/{$this->leaseId}/sign", [
                'request_id' => $this->esigRequestId,
                'full_name'  => 'Lessee Hunter',
                'agreed'     => true,
            ])
            ->assertSessionMissing('error');

        $this->assertSame('signed', $this->lesseeSignerStatus(), 'signature should be recorded once the booking deposit is collected');
    }
}
