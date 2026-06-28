<?php

namespace Tests\Feature\Member;

use App\Models\Billing\SecurityDeposit;
use App\Models\Incidents\LeaseDispute;
use App\Services\Billing\SecurityDepositService;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The member-portal forfeiture-contest route (db.system) authors a LeaseDispute and
 * attaches the uploaded photo evidence. Lease + deposit rows are real (owner role in
 * tests bypasses RLS); storage and the virus-scan queue are faked.
 */
class ContestForfeitureTest extends TestCase
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
        Storage::fake(config('filesystems.defaults.documents', 'local'));
        Queue::fake();

        $this->hunterId     = (string) Str::uuid();
        $this->landownerId  = (string) Str::uuid();
        $this->leaseId      = (string) Str::uuid();
        $this->applicationId = (string) Str::uuid();

        foreach ([[$this->hunterId, 'hunter'], [$this->landownerId, 'landowner']] as [$id, $type]) {
            DB::connection('identity')->table('users')->insert([
                'id'            => $id,
                'email'         => "contest-{$type}-{$id}@test.invalid",
                'password_hash' => 'test-hash',
                'status'        => 'active',
                'account_type'  => $type,
                'trust_score'   => 80,
            ]);
            // Fully populate the account — a leaked fixture (interrupted run) should
            // never show up nameless on /admin/platform-users.
            DB::connection('identity')->table('user_profiles')->insert([
                'id'         => (string) Str::uuid(),
                'user_id'    => $id,
                'first_name' => 'Contest',
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
            'status'         => 'active',
            'start_date'     => '2026-10-01',
            'end_date'       => '2026-11-30',
            'total_price'    => '2500.00',
            'deposit_paid'   => '500.00',
        ]);

        $deposit = SecurityDeposit::create([
            'lease_id'                 => $this->leaseId,
            'payer_user_id'            => $this->hunterId,
            'payee_user_id'            => $this->landownerId,
            'amount_cents'             => 5000,
            'currency'                 => 'USD',
            'status'                   => 'held',
            'stripe_payment_intent_id' => 'pi_' . Str::random(12),
            'held_at'                  => now(),
        ]);
        $this->depositId = $deposit->id;

        // Park a pending hunter-fault forfeiture so the deposit is contestable.
        app(SecurityDepositService::class)->forfeit(
            $deposit->id, 5000, 'Cabin damage', $this->landownerId, SecurityDepositService::FAULT_LESSEE, 'property_damage',
        );
    }

    protected function tearDown(): void
    {
        $disputeIds = DB::connection('incidents')->table('lease_disputes')->where('lease_id', $this->leaseId)->pluck('id')->all();
        DB::connection('incidents')->table('lease_disputes')->where('lease_id', $this->leaseId)->delete();
        unset($disputeIds);

        DB::connection('billing')->table('security_deposits')->where('id', $this->depositId)->delete();
        DB::connection('documents')->table('documents')->whereIn('owner_user_id', [$this->hunterId, $this->landownerId])->delete();
        DB::connection('lease')->table('leases')->where('id', $this->leaseId)->delete();
        DB::connection('lease')->table('lease_applications')->where('id', $this->applicationId)->delete();
        DB::connection('identity')->table('user_profiles')->whereIn('user_id', [$this->hunterId, $this->landownerId])->delete();
        DB::connection('identity')->table('users')->whereIn('id', [$this->hunterId, $this->landownerId])->delete();

        parent::tearDown();
    }

    public function test_contest_route_files_a_dispute_with_evidence(): void
    {
        $this->withSession(['auth.user_id' => $this->hunterId])
            ->post("/member/leases/{$this->leaseId}/forfeiture/contest", [
                'description' => 'The damage was there before my hunt.',
                'evidence'    => [UploadedFile::fake()->image('proof.jpg', 600, 400)],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $dispute = LeaseDispute::where('security_deposit_id', $this->depositId)->first();
        $this->assertNotNull($dispute, 'the contest should author a dispute row');
        $this->assertSame('open', $dispute->status);
        $this->assertSame($this->hunterId, $dispute->initiator_user_id);
        $this->assertSame($this->landownerId, $dispute->respondent_user_id);
        $this->assertCount(1, (array) $dispute->evidence_document_ids, 'the photo evidence should be attached');
    }

    public function test_contest_route_rejects_a_non_lessee(): void
    {
        $strangerId = (string) Str::uuid();
        DB::connection('identity')->table('users')->insert([
            'id' => $strangerId, 'email' => "stranger-{$strangerId}@test.invalid",
            'password_hash' => 'test-hash', 'status' => 'active', 'account_type' => 'hunter',
        ]);
        DB::connection('identity')->table('user_profiles')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $strangerId,
            'first_name' => 'Stranger', 'last_name' => 'Test',
        ]);

        $this->withSession(['auth.user_id' => $strangerId])
            ->post("/member/leases/{$this->leaseId}/forfeiture/contest", [
                'description' => 'Not my lease.',
            ])
            ->assertNotFound();

        $this->assertSame(0, LeaseDispute::where('security_deposit_id', $this->depositId)->count());

        DB::connection('identity')->table('user_profiles')->where('user_id', $strangerId)->delete();
        DB::connection('identity')->table('users')->where('id', $strangerId)->delete();
    }
}
