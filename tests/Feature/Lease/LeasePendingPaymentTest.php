<?php

namespace Tests\Feature\Lease;

use App\Services\Billing\LeasePaymentService;
use App\Services\Lease\CheckInService;
use App\Services\Lease\LeaseService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A fully-signed lease must not become field-usable until its balance is paid.
 *
 *  - finalizeSignatures() holds the lease in 'pending_payment' when a balance is
 *    owed, and activates immediately when nothing is owed.
 *  - Check-in (and, by the same status gate, gate QR + stand map) is refused
 *    while a lease is 'pending_payment'.
 *  - Settling the balance via the lease-payment webhook promotes the lease to
 *    'active'.
 *
 * Postgres fixtures via owner connections (testing runs as the owner role).
 */
class LeasePendingPaymentTest extends TestCase
{
    private array $leaseIds = [];
    private array $applicationIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! config('database.connections.lease')) {
            $this->markTestSkipped('lease connection not configured.');
        }
    }

    protected function tearDown(): void
    {
        if (! empty($this->leaseIds)) {
            DB::connection('billing')->table('lease_payments')->whereIn('lease_id', $this->leaseIds)->delete();
            DB::connection('lease')->table('leases')->whereIn('id', $this->leaseIds)->delete();
        }
        if (! empty($this->applicationIds)) {
            DB::connection('lease')->table('lease_applications')->whereIn('id', $this->applicationIds)->delete();
        }

        parent::tearDown();
    }

    /**
     * Insert a lease (and its required application) directly, returning its id.
     * No property/listing rows are needed — those are cross-DB UUIDs with no FK.
     */
    private function makeLease(string $status, float $totalPrice, ?string $lesseeId = null): string
    {
        $applicationId = (string) Str::uuid();
        $leaseId       = (string) Str::uuid();
        $listingId     = (string) Str::uuid();
        $lesseeId    ??= (string) Str::uuid();

        DB::connection('lease')->table('lease_applications')->insert([
            'id'                   => $applicationId,
            'listing_id'           => $listingId,
            'applicant_user_id'    => $lesseeId,
            'application_type'     => 'individual',
            'status'               => 'approved',
            'property_id_snapshot' => (string) Str::uuid(),
        ]);

        DB::connection('lease')->table('leases')->insert([
            'id'             => $leaseId,
            'application_id' => $applicationId,
            'property_id'    => (string) Str::uuid(),
            'listing_id'     => $listingId,
            'lessee_user_id' => $lesseeId,
            'lessor_user_id' => (string) Str::uuid(),
            'status'         => $status,
            'start_date'     => '2026-10-15',
            'end_date'       => '2027-01-08',
            'total_price'    => number_format($totalPrice, 2, '.', ''),
        ]);

        $this->applicationIds[] = $applicationId;
        $this->leaseIds[]       = $leaseId;

        return $leaseId;
    }

    private function leaseStatus(string $leaseId): string
    {
        return DB::connection('lease')->table('leases')->where('id', $leaseId)->value('status');
    }

    public function test_finalize_signatures_holds_lease_in_pending_payment_when_a_balance_is_due(): void
    {
        $leaseId = $this->makeLease('pending_signatures', 5000.00);

        app(LeaseService::class)->finalizeSignatures($leaseId);

        $this->assertSame('pending_payment', $this->leaseStatus($leaseId),
            'A signed lease with an outstanding balance must wait in pending_payment, not activate.');
    }

    public function test_finalize_signatures_activates_immediately_when_nothing_is_owed(): void
    {
        $leaseId = $this->makeLease('pending_signatures', 0.00);

        app(LeaseService::class)->finalizeSignatures($leaseId);

        $this->assertSame('active', $this->leaseStatus($leaseId),
            'A signed lease with a zero balance should activate on signing.');
    }

    public function test_check_in_is_refused_while_a_lease_is_pending_payment(): void
    {
        $lesseeId = (string) Str::uuid();
        $leaseId  = $this->makeLease('pending_payment', 5000.00, $lesseeId);

        $checkIn  = app(CheckInService::class);
        $lease    = \App\Models\Lease\Lease::find($leaseId);

        $this->assertFalse($checkIn->mayCheckIn($lesseeId, $leaseId),
            'A pending_payment lease must not permit check-in.');
        $this->assertNull($checkIn->activeLeaseForUserProperty($lesseeId, $lease->property_id),
            'A pending_payment lease must not resolve as the active lease for the property.');
    }

    public function test_settling_the_balance_via_the_webhook_activates_the_lease(): void
    {
        $lesseeId = (string) Str::uuid();
        $lessorId = (string) Str::uuid();
        $leaseId  = $this->makeLease('pending_payment', 1000.00, $lesseeId);

        // Mirror the checkout.session.completed payload the webhook hands the
        // service: gross fully covers the $1,000 balance (no surcharge), so the
        // recomputed balance hits zero and the lease should flip to active.
        app(LeasePaymentService::class)->recordCollectedFromCheckout([
            'payment_intent' => 'pi_test_' . Str::lower(Str::random(16)),
            'payment_status' => 'paid',
            'currency'       => 'usd',
            'amount_total'   => 100000,
            'metadata'       => [
                'purpose'         => 'lease_payment',
                'lease_id'        => $leaseId,
                'payer_user_id'   => $lesseeId,
                'payee_user_id'   => $lessorId,
                'gross_cents'     => '100000',
                'surcharge_cents' => '0',
            ],
        ]);

        $this->assertSame('active', $this->leaseStatus($leaseId),
            'A balance-clearing lease payment must promote pending_payment → active.');
    }
}
