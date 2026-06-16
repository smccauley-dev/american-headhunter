<?php

namespace Tests\Feature\Lease;

use App\Services\Lease\LeaseService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * LeaseService::getLeaseSummariesForLessee — the per-user "needs_my_signature"
 * flag that drives the "Sign Now" button on the member portal. A lease stays
 * 'pending_signatures' until both parties sign, so this flag (not the status)
 * decides whether the current lessee still owes a signature.
 *
 * Isolation: DB inserts in setUp, deleted in tearDown.
 */
class LeaseSummariesTest extends TestCase
{
    private string $userId;
    private string $lessorUserId;
    private string $applicationId;
    private string $leaseId;
    private string $esigRequestId;
    private string $esigSignerId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId        = (string) Str::uuid();
        $this->lessorUserId  = (string) Str::uuid();
        $this->applicationId = (string) Str::uuid();
        $this->leaseId       = (string) Str::uuid();
        $this->esigRequestId = (string) Str::uuid();
        $this->esigSignerId  = (string) Str::uuid();

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
            'subject'           => 'Hunting Lease Agreement — 2026',
            'requested_at'      => now(),
        ]);

        DB::connection('documents')->table('esignature_signers')->insert([
            'id'         => $this->esigSignerId,
            'request_id' => $this->esigRequestId,
            'user_id'    => $this->userId,
            'email'      => "lessee-{$this->userId}@example.com",
            'name'       => 'Lessee Signer',
            'order_num'  => 2,
            'status'     => 'pending',
        ]);
    }

    protected function tearDown(): void
    {
        DB::connection('documents')->table('esignature_signers')->where('id', $this->esigSignerId)->delete();
        DB::connection('documents')->table('esignature_requests')->where('id', $this->esigRequestId)->delete();
        DB::connection('lease')->table('leases')->where('id', $this->leaseId)->delete();
        DB::connection('lease')->table('lease_applications')->where('id', $this->applicationId)->delete();

        foreach (['lease', 'documents'] as $conn) {
            try { DB::connection($conn)->disconnect(); } catch (\Throwable) {}
        }

        parent::tearDown();
    }

    private function summary(): array
    {
        $summaries = app(LeaseService::class)->getLeaseSummariesForLessee($this->userId);
        $lease = collect($summaries)->firstWhere('id', $this->leaseId);
        $this->assertNotNull($lease, 'Expected the pending lease in the lessee summaries.');

        return $lease;
    }

    public function test_needs_my_signature_is_true_when_the_lessee_has_not_signed(): void
    {
        $lease = $this->summary();

        $this->assertSame('pending_signatures', $lease['status']);
        $this->assertTrue($lease['needs_my_signature']);
    }

    public function test_needs_my_signature_is_false_once_the_lessee_has_signed(): void
    {
        // The lessee signs; the lease is still 'pending_signatures' awaiting the
        // landowner's countersignature, but the lessee no longer owes anything.
        DB::connection('documents')->table('esignature_signers')
            ->where('id', $this->esigSignerId)
            ->update(['status' => 'signed', 'signed_at' => now()]);

        $lease = $this->summary();

        $this->assertSame('pending_signatures', $lease['status']);
        $this->assertFalse($lease['needs_my_signature']);
    }

    public function test_needs_my_signature_is_false_for_an_active_lease(): void
    {
        DB::connection('lease')->table('leases')
            ->where('id', $this->leaseId)
            ->update(['status' => 'active']);

        $lease = $this->summary();

        $this->assertSame('active', $lease['status']);
        $this->assertFalse($lease['needs_my_signature']);
    }
}
