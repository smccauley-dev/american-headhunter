<?php

namespace Tests\Feature\Incidents;

use App\Models\Billing\SecurityDeposit;
use App\Models\Incidents\LeaseDispute;
use App\Models\Identity\User;
use App\Models\Lease\Lease;
use App\Services\Audit\AuditService;
use App\Services\Billing\PayoutService;
use App\Services\Billing\SecurityDepositService;
use App\Services\Billing\StripeService;
use App\Services\Documents\DocumentService;
use App\Services\Identity\TrustScoreService;
use App\Services\Incidents\DisputeService;
use App\Services\Property\PropertyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Stripe\Refund;
use Tests\TestCase;

/**
 * DB 10 dispute (forfeiture-contest) loop. The deposit rows are real on `billing`,
 * the dispute rows real on `incidents` (owner role in tests bypasses RLS). Stripe is
 * mocked; settlement + Trust Score move only at the adjudication outcome.
 */
class DisputeServiceTest extends TestCase
{
    /** @var array<int,string> */ private array $depositIds = [];
    /** @var array<int,string> */ private array $disputeIds = [];
    /** @var array<int,string> */ private array $userIds = [];

    protected function tearDown(): void
    {
        if ($this->disputeIds) {
            DB::connection('incidents')->table('lease_disputes')->whereIn('id', $this->disputeIds)->delete();
        }
        if ($this->depositIds) {
            DB::connection('billing')->table('security_deposits')->whereIn('id', $this->depositIds)->delete();
        }
        if ($this->userIds) {
            DB::connection('identity')->table('trust_score_events')->whereIn('user_id', $this->userIds)->delete();
            DB::connection('identity')->table('users')->whereIn('id', $this->userIds)->delete();
        }
        parent::tearDown();
    }

    private function makeUser(string $accountType, int $trustScore = 50): string
    {
        $id = (string) Str::uuid();
        DB::connection('identity')->table('users')->insert([
            'id'            => $id,
            'email'         => "dispute-{$accountType}-{$id}@test.invalid",
            'password_hash' => 'test-hash',
            'status'        => 'active',
            'account_type'  => $accountType,
            'trust_score'   => $trustScore,
        ]);
        $this->userIds[] = $id;

        return $id;
    }

    private function lease(string $leaseId): Lease
    {
        return (new Lease())->forceFill(['id' => $leaseId]);
    }

    private function seedHeld(string $leaseId, string $payerId, string $payeeId, int $amount = 5000): SecurityDeposit
    {
        $deposit = SecurityDeposit::create([
            'lease_id'                 => $leaseId,
            'payer_user_id'            => $payerId,
            'payee_user_id'            => $payeeId,
            'amount_cents'             => $amount,
            'currency'                 => 'USD',
            'status'                   => 'held',
            'stripe_payment_intent_id' => 'pi_' . Str::random(12),
            'held_at'                  => now(),
        ]);
        $this->depositIds[] = $deposit->id;

        return $deposit;
    }

    private function deposits(?StripeService $stripe = null): SecurityDepositService
    {
        return new SecurityDepositService(
            $stripe ?? app(StripeService::class),
            app(PropertyService::class),
            app(AuditService::class),
            app(PayoutService::class),
            app(TrustScoreService::class),
            app(\App\Services\Billing\FeeService::class),
        );
    }

    private function disputes(SecurityDepositService $deposits): DisputeService
    {
        return new DisputeService(
            $deposits,
            app(TrustScoreService::class),
            app(DocumentService::class),
            app(AuditService::class),
        );
    }

    /** Forfeit a freshly-held deposit so it parks a pending claim ready to contest. */
    private function pendingForfeit(SecurityDepositService $deposits, SecurityDeposit $deposit): void
    {
        $deposits->forfeit($deposit->id, 5000, 'Cabin damage', (string) Str::uuid(), SecurityDepositService::FAULT_LESSEE, 'property_damage');
    }

    private function track(LeaseDispute $dispute): LeaseDispute
    {
        $this->disputeIds[] = $dispute->id;

        return $dispute;
    }

    // ── filing a contest ─────────────────────────────────────────────────────────

    public function test_file_contest_opens_a_dispute(): void
    {
        $hunter    = $this->makeUser('hunter');
        $landowner = $this->makeUser('landowner');
        $leaseId   = (string) Str::uuid();

        $deposits = $this->deposits();
        $deposit  = $this->seedHeld($leaseId, $hunter, $landowner);
        $this->pendingForfeit($deposits, $deposit);

        $dispute = $this->track($this->disputes($deposits)->fileForfeitureContest(
            $this->lease($leaseId), (new User())->forceFill(['id' => $hunter]), 'Damage was pre-existing.',
        ));

        $this->assertSame('open', $dispute->status);
        $this->assertSame('damage', $dispute->dispute_type);
        $this->assertSame($deposit->id, $dispute->security_deposit_id);
        $this->assertSame($hunter, $dispute->initiator_user_id);
        $this->assertSame($landowner, $dispute->respondent_user_id);
        $this->assertSame(5000, (int) $dispute->amount_disputed_cents);
    }

    public function test_file_contest_requires_a_pending_forfeiture(): void
    {
        $hunter  = $this->makeUser('hunter');
        $leaseId = (string) Str::uuid();
        $deposits = $this->deposits();
        $this->seedHeld($leaseId, $hunter, $this->makeUser('landowner')); // held, never forfeited

        $this->expectException(\RuntimeException::class);
        $this->disputes($deposits)->fileForfeitureContest(
            $this->lease($leaseId), (new User())->forceFill(['id' => $hunter]), 'No forfeiture to contest.',
        );
    }

    public function test_file_contest_rejects_a_non_payer(): void
    {
        $hunter    = $this->makeUser('hunter');
        $stranger  = $this->makeUser('hunter');
        $leaseId   = (string) Str::uuid();
        $deposits  = $this->deposits();
        $deposit   = $this->seedHeld($leaseId, $hunter, $this->makeUser('landowner'));
        $this->pendingForfeit($deposits, $deposit);

        $this->expectException(\RuntimeException::class);
        $this->disputes($deposits)->fileForfeitureContest(
            $this->lease($leaseId), (new User())->forceFill(['id' => $stranger]), 'Not my deposit.',
        );
    }

    public function test_file_contest_rejects_a_duplicate(): void
    {
        $hunter   = $this->makeUser('hunter');
        $leaseId  = (string) Str::uuid();
        $deposits = $this->deposits();
        $deposit  = $this->seedHeld($leaseId, $hunter, $this->makeUser('landowner'));
        $this->pendingForfeit($deposits, $deposit);

        $svc   = $this->disputes($deposits);
        $payer = (new User())->forceFill(['id' => $hunter]);
        $this->track($svc->fileForfeitureContest($this->lease($leaseId), $payer, 'First.'));

        $this->expectException(\RuntimeException::class);
        $svc->fileForfeitureContest($this->lease($leaseId), $payer, 'Second.');
    }

    // ── adjudication outcomes ─────────────────────────────────────────────────────

    public function test_uphold_applies_the_hunter_penalty(): void
    {
        $hunter    = $this->makeUser('hunter', 80);
        $landowner = $this->makeUser('landowner', 60);
        $leaseId   = (string) Str::uuid();

        $stripe   = Mockery::mock(StripeService::class);
        $stripe->shouldNotReceive('refundPaymentIntent'); // full keep
        $deposits = $this->deposits($stripe);
        $deposit  = $this->seedHeld($leaseId, $hunter, $landowner);
        $this->pendingForfeit($deposits, $deposit);

        $svc     = $this->disputes($deposits);
        $dispute = $this->track($svc->fileForfeitureContest($this->lease($leaseId), (new User())->forceFill(['id' => $hunter]), 'Contest.'));
        $svc->resolve($dispute->id, DisputeService::OUTCOME_UPHOLD, (string) Str::uuid());

        $this->assertSame('resolved', LeaseDispute::find($dispute->id)->status);
        $this->assertSame('applied', SecurityDeposit::find($deposit->id)->forfeit_trust_status);
        $this->assertSame(70, (int) DB::connection('identity')->table('users')->where('id', $hunter)->value('trust_score'));
        $this->assertSame(60, (int) DB::connection('identity')->table('users')->where('id', $landowner)->value('trust_score'));
    }

    public function test_overturn_refunds_and_docks_the_landowner(): void
    {
        $hunter    = $this->makeUser('hunter', 80);
        $landowner = $this->makeUser('landowner', 60);
        $leaseId   = (string) Str::uuid();

        $stripe = Mockery::mock(StripeService::class);
        $stripe->shouldReceive('refundPaymentIntent')->once()->andReturn(Refund::constructFrom(['id' => 're_ov']));
        $deposits = $this->deposits($stripe);
        $deposit  = $this->seedHeld($leaseId, $hunter, $landowner);
        $this->pendingForfeit($deposits, $deposit);

        $svc     = $this->disputes($deposits);
        $dispute = $this->track($svc->fileForfeitureContest($this->lease($leaseId), (new User())->forceFill(['id' => $hunter]), 'Contest.'));
        $svc->resolve($dispute->id, DisputeService::OUTCOME_OVERTURN, (string) Str::uuid());

        $this->assertSame('released', SecurityDeposit::find($deposit->id)->status);
        $this->assertSame('waived', SecurityDeposit::find($deposit->id)->forfeit_trust_status);
        // Landowner docked -10 for an unjustified forfeiture; hunter not credited by default.
        $this->assertSame(50, (int) DB::connection('identity')->table('users')->where('id', $landowner)->value('trust_score'));
        $this->assertSame(80, (int) DB::connection('identity')->table('users')->where('id', $hunter)->value('trust_score'));
        $this->assertSame(1, DB::connection('identity')->table('trust_score_events')
            ->where('user_id', $landowner)->where('event_type', 'dispute_resolved_against_user')->count());
    }

    public function test_overturn_can_credit_the_vindicated_hunter(): void
    {
        $hunter    = $this->makeUser('hunter', 80);
        $landowner = $this->makeUser('landowner', 60);
        $leaseId   = (string) Str::uuid();

        $stripe = Mockery::mock(StripeService::class);
        $stripe->shouldReceive('refundPaymentIntent')->once()->andReturn(Refund::constructFrom(['id' => 're_ov2']));
        $deposits = $this->deposits($stripe);
        $deposit  = $this->seedHeld($leaseId, $hunter, $landowner);
        $this->pendingForfeit($deposits, $deposit);

        $svc     = $this->disputes($deposits);
        $dispute = $this->track($svc->fileForfeitureContest($this->lease($leaseId), (new User())->forceFill(['id' => $hunter]), 'Contest.'));
        $svc->resolve($dispute->id, DisputeService::OUTCOME_OVERTURN, (string) Str::uuid(), null, ['credit_initiator' => true]);

        // Hunter +5 for being vindicated.
        $this->assertSame(85, (int) DB::connection('identity')->table('users')->where('id', $hunter)->value('trust_score'));
    }

    public function test_opt_out_settles_via_insurance_without_score(): void
    {
        $hunter    = $this->makeUser('hunter', 80);
        $landowner = $this->makeUser('landowner', 60);
        $leaseId   = (string) Str::uuid();

        $stripe = Mockery::mock(StripeService::class);
        $stripe->shouldNotReceive('refundPaymentIntent'); // keep, full claim
        $deposits = $this->deposits($stripe);
        $deposit  = $this->seedHeld($leaseId, $hunter, $landowner);
        $this->pendingForfeit($deposits, $deposit);

        $svc     = $this->disputes($deposits);
        $dispute = $this->track($svc->fileForfeitureContest($this->lease($leaseId), (new User())->forceFill(['id' => $hunter]), 'Contest.'));
        $svc->resolve($dispute->id, DisputeService::OUTCOME_OPT_OUT, (string) Str::uuid(), 'Insurer handling', [
            'disposition' => 'keep',
            'insurance'   => ['covered_party' => 'landowner', 'insurer_name' => 'Acme Mutual'],
        ]);

        $this->assertSame('opted_out', SecurityDeposit::find($deposit->id)->forfeit_trust_status);
        // Neither party's score moves.
        $this->assertSame(80, (int) DB::connection('identity')->table('users')->where('id', $hunter)->value('trust_score'));
        $this->assertSame(60, (int) DB::connection('identity')->table('users')->where('id', $landowner)->value('trust_score'));
    }

    public function test_resolve_rejects_a_closed_dispute(): void
    {
        $hunter   = $this->makeUser('hunter', 80);
        $leaseId  = (string) Str::uuid();
        $stripe   = Mockery::mock(StripeService::class);
        $stripe->shouldNotReceive('refundPaymentIntent');
        $deposits = $this->deposits($stripe);
        $deposit  = $this->seedHeld($leaseId, $hunter, $this->makeUser('landowner'));
        $this->pendingForfeit($deposits, $deposit);

        $svc     = $this->disputes($deposits);
        $dispute = $this->track($svc->fileForfeitureContest($this->lease($leaseId), (new User())->forceFill(['id' => $hunter]), 'Contest.'));
        $svc->resolve($dispute->id, DisputeService::OUTCOME_UPHOLD, (string) Str::uuid());

        $this->expectException(\RuntimeException::class);
        $svc->resolve($dispute->id, DisputeService::OUTCOME_UPHOLD, (string) Str::uuid());
    }
}
