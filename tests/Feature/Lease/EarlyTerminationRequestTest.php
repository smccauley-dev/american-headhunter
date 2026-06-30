<?php

namespace Tests\Feature\Lease;

use App\Models\Billing\SecurityDeposit;
use App\Models\Lease\LeaseTerminationRequest;
use App\Services\Lease\LeaseService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Hunter-requested early termination: the lessee asks to end an active lease early
 * and the lessor approves or denies. On approval the lease is terminated and the
 * hunter forfeits the security deposit as a NON-contestable early-exit penalty —
 * settled immediately, kept by the landowner, no Trust hit (distinct from the
 * landowner's violation termination, which files a contestable claim).
 *
 * Real rows on the lease/billing connections (tests run as owner → RLS bypassed).
 * No Stripe: with no payouts-enabled landowner the kept forfeiture's disbursement
 * defers, so the deposit still settles to 'forfeited' without leaving the box.
 */
class EarlyTerminationRequestTest extends TestCase
{
    private string $applicationId;
    private string $leaseId;
    private string $lesseeId;
    private string $lessorId;
    private ?string $depositId = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->applicationId = (string) Str::uuid();
        $this->leaseId       = (string) Str::uuid();
        $this->lesseeId      = (string) Str::uuid();
        $this->lessorId      = (string) Str::uuid();

        DB::connection('lease')->table('lease_applications')->insert([
            'id'                => $this->applicationId,
            'listing_id'        => (string) Str::uuid(),
            'applicant_user_id' => $this->lesseeId,
            'application_type'  => 'individual',
            'status'            => 'approved',
        ]);

        DB::connection('lease')->table('leases')->insert([
            'id'             => $this->leaseId,
            'application_id' => $this->applicationId,
            'property_id'    => (string) Str::uuid(),
            'listing_id'     => (string) Str::uuid(),
            'lessee_user_id' => $this->lesseeId,
            'lessor_user_id' => $this->lessorId,
            'status'         => 'active',
            'start_date'     => '2026-10-01',
            'end_date'       => '2026-11-30',
            'total_price'    => '2500.00',
            'deposit_paid'   => '0.00',
        ]);
    }

    protected function tearDown(): void
    {
        DB::connection('lease')->table('lease_termination_requests')->where('lease_id', $this->leaseId)->delete();
        if ($this->depositId) {
            DB::connection('billing')->table('security_deposits')->where('id', $this->depositId)->delete();
        }
        DB::connection('lease')->table('leases')->where('id', $this->leaseId)->delete();
        DB::connection('lease')->table('lease_applications')->where('id', $this->applicationId)->delete();

        parent::tearDown();
    }

    private function seedHeldDeposit(int $amountCents = 50000): SecurityDeposit
    {
        $deposit = SecurityDeposit::create([
            'lease_id'                 => $this->leaseId,
            'payer_user_id'            => $this->lesseeId,
            'payee_user_id'            => $this->lessorId,
            'amount_cents'             => $amountCents,
            'currency'                 => 'USD',
            'status'                   => 'held',
            'stripe_payment_intent_id' => 'pi_' . Str::random(12),
        ]);
        $this->depositId = $deposit->id;

        return $deposit;
    }

    private function service(): LeaseService
    {
        return app(LeaseService::class);
    }

    public function test_the_hunter_can_request_early_termination(): void
    {
        $request = $this->service()->requestEarlyTermination($this->leaseId, 'Job relocation out of state', $this->lesseeId);

        $this->assertSame('pending', $request->status);
        $this->assertSame($this->lesseeId, $request->requested_by_user_id);
        $this->assertDatabaseHas('lease_termination_requests', [
            'id'       => $request->id,
            'lease_id' => $this->leaseId,
            'status'   => 'pending',
        ], 'lease');
    }

    public function test_only_the_lessee_may_request(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->service()->requestEarlyTermination($this->leaseId, 'Not my lease', $this->lessorId);
    }

    public function test_cannot_request_on_a_non_active_lease(): void
    {
        DB::connection('lease')->table('leases')->where('id', $this->leaseId)->update(['status' => 'expired']);

        $this->expectException(\RuntimeException::class);

        $this->service()->requestEarlyTermination($this->leaseId, 'Too late', $this->lesseeId);
    }

    public function test_a_second_open_request_is_rejected(): void
    {
        $this->service()->requestEarlyTermination($this->leaseId, 'First', $this->lesseeId);

        $this->expectException(\RuntimeException::class);

        $this->service()->requestEarlyTermination($this->leaseId, 'Second', $this->lesseeId);
    }

    public function test_landowner_approval_terminates_and_forfeits_the_deposit_non_contestably(): void
    {
        $deposit = $this->seedHeldDeposit(50000);
        $request = $this->service()->requestEarlyTermination($this->leaseId, 'Relocating', $this->lesseeId);

        $this->service()->approveEarlyTermination($request->id, 'Understood — good luck.', $this->lessorId);

        $request->refresh();
        $this->assertSame('approved', $request->status);
        $this->assertSame($this->lessorId, $request->decided_by_user_id);
        $this->assertNotNull($request->decided_at);

        $lease = DB::connection('lease')->table('leases')->where('id', $this->leaseId)->first();
        $this->assertSame('terminated', $lease->status);
        $this->assertNotNull($lease->terminated_at);

        $deposit->refresh();
        $this->assertSame('landowner_initiated', $deposit->forfeit_fault); // non-contestable
        $this->assertSame('forfeited', $deposit->status);                  // settled immediately
        $this->assertNull($deposit->forfeit_trust_status);                 // no Trust hit
        $this->assertSame(50000, (int) $deposit->forfeited_amount_cents);
    }

    public function test_only_the_lessor_may_decide(): void
    {
        $request = $this->service()->requestEarlyTermination($this->leaseId, 'Relocating', $this->lesseeId);

        $this->expectException(\RuntimeException::class);

        $this->service()->approveEarlyTermination($request->id, null, $this->lesseeId);
    }

    public function test_landowner_denial_keeps_the_lease_active_and_the_deposit_held(): void
    {
        $deposit = $this->seedHeldDeposit(50000);
        $request = $this->service()->requestEarlyTermination($this->leaseId, 'Relocating', $this->lesseeId);

        $this->service()->denyEarlyTermination($request->id, 'Sorry, the season is underway.', $this->lessorId);

        $request->refresh();
        $this->assertSame('denied', $request->status);

        $lease = DB::connection('lease')->table('leases')->where('id', $this->leaseId)->first();
        $this->assertSame('active', $lease->status);
        $this->assertNull($lease->terminated_at);

        $deposit->refresh();
        $this->assertSame('held', $deposit->status);
        $this->assertNull($deposit->forfeit_fault);
    }

    public function test_an_already_decided_request_cannot_be_decided_again(): void
    {
        $request = $this->service()->requestEarlyTermination($this->leaseId, 'Relocating', $this->lesseeId);
        $this->service()->denyEarlyTermination($request->id, null, $this->lessorId);

        $this->expectException(\RuntimeException::class);

        $this->service()->approveEarlyTermination($request->id, null, $this->lessorId);
    }
}
