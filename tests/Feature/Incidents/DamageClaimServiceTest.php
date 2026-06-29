<?php

namespace Tests\Feature\Incidents;

use App\Models\Billing\SecurityDeposit;
use App\Models\Identity\User;
use App\Models\Incidents\DamageClaim;
use App\Models\Lease\Lease;
use App\Services\Audit\AuditService;
use App\Services\Billing\PayoutService;
use App\Services\Billing\SecurityDepositService;
use App\Services\Billing\StripeService;
use App\Services\Documents\DocumentService;
use App\Services\Identity\TrustScoreService;
use App\Services\Incidents\DamageClaimService;
use App\Services\Property\PropertyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * DB 10 damage-claim intake & review. Claim rows are real on `incidents`, deposit
 * rows real on `billing` (owner role bypasses RLS in tests). An approved claim can
 * drive a deposit forfeiture-claim through SecurityDepositService.
 */
class DamageClaimServiceTest extends TestCase
{
    /** @var array<int,string> */ private array $claimIds = [];
    /** @var array<int,string> */ private array $depositIds = [];
    /** @var array<int,string> */ private array $userIds = [];

    protected function tearDown(): void
    {
        if ($this->claimIds) {
            DB::connection('incidents')->table('damage_claims')->whereIn('id', $this->claimIds)->delete();
        }
        if ($this->depositIds) {
            DB::connection('billing')->table('security_deposits')->whereIn('id', $this->depositIds)->delete();
        }
        if ($this->userIds) {
            DB::connection('identity')->table('users')->whereIn('id', $this->userIds)->delete();
        }
        parent::tearDown();
    }

    private function makeUser(string $accountType): string
    {
        $id = (string) Str::uuid();
        DB::connection('identity')->table('users')->insert([
            'id'            => $id,
            'email'         => "claim-{$accountType}-{$id}@test.invalid",
            'password_hash' => 'test-hash',
            'status'        => 'active',
            'account_type'  => $accountType,
            'trust_score'   => 50,
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

    private function claims(?SecurityDepositService $deposits = null): DamageClaimService
    {
        return new DamageClaimService(
            app(DocumentService::class),
            $deposits ?? $this->deposits(),
            app(AuditService::class),
        );
    }

    private function track(DamageClaim $claim): DamageClaim
    {
        $this->claimIds[] = $claim->id;

        return $claim;
    }

    public function test_file_creates_a_submitted_claim(): void
    {
        $landowner = $this->makeUser('landowner');
        $claim = $this->track($this->claims()->file(
            $this->lease((string) Str::uuid()), (new User())->forceFill(['id' => $landowner]),
            'property_damage', 25000, 'Broken cabin window and torn screen door.',
            [], ['covered_party' => 'landowner', 'insurer_name' => 'Acme Mutual'],
        ));

        $this->assertSame('submitted', $claim->status);
        $this->assertSame(25000, (int) $claim->amount_claimed_cents);
        $this->assertSame($landowner, $claim->claimant_user_id);
        $this->assertSame('claimed', $claim->coverage_status);
    }

    public function test_file_rejects_a_non_positive_amount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->claims()->file(
            $this->lease((string) Str::uuid()), (new User())->forceFill(['id' => $this->makeUser('landowner')]),
            'property_damage', 0, 'Nothing.',
        );
    }

    public function test_review_approves_with_an_amount(): void
    {
        $claim = $this->track($this->claims()->file(
            $this->lease((string) Str::uuid()), (new User())->forceFill(['id' => $this->makeUser('landowner')]),
            'property_damage', 25000, 'Damage.',
        ));

        $result = $this->claims()->review($claim->id, DamageClaimService::DECISION_APPROVE, 18000, (string) Str::uuid(), 'Partially approved');

        $this->assertSame('approved', $result->status);
        $this->assertSame(18000, (int) $result->amount_approved_cents);
        $this->assertSame('Partially approved', $result->review_notes);
    }

    public function test_review_approve_rejects_an_over_claim_amount(): void
    {
        $claim = $this->track($this->claims()->file(
            $this->lease((string) Str::uuid()), (new User())->forceFill(['id' => $this->makeUser('landowner')]),
            'property_damage', 25000, 'Damage.',
        ));

        $this->expectException(\InvalidArgumentException::class);
        $this->claims()->review($claim->id, DamageClaimService::DECISION_APPROVE, 30000, (string) Str::uuid());
    }

    public function test_review_denies(): void
    {
        $claim = $this->track($this->claims()->file(
            $this->lease((string) Str::uuid()), (new User())->forceFill(['id' => $this->makeUser('landowner')]),
            'property_damage', 25000, 'Damage.',
        ));

        $result = $this->claims()->review($claim->id, DamageClaimService::DECISION_DENY, null, (string) Str::uuid(), 'No evidence');

        $this->assertSame('denied', $result->status);
        $this->assertNotNull($result->resolved_at);
    }

    public function test_review_marks_covered_by_insurance(): void
    {
        $claim = $this->track($this->claims()->file(
            $this->lease((string) Str::uuid()), (new User())->forceFill(['id' => $this->makeUser('landowner')]),
            'property_damage', 25000, 'Damage.', [], ['covered_party' => 'landowner'],
        ));

        $result = $this->claims()->review($claim->id, DamageClaimService::DECISION_COVERED, null, (string) Str::uuid());

        $this->assertSame('covered', $result->status);
        $this->assertSame('covered', $result->coverage_status);
    }

    public function test_forfeit_for_approved_claims_the_deposit(): void
    {
        $hunter    = $this->makeUser('hunter');
        $landowner = $this->makeUser('landowner');
        $leaseId   = (string) Str::uuid();

        $stripe = Mockery::mock(StripeService::class);
        $stripe->shouldNotReceive('refundPaymentIntent'); // forfeit only records a claim
        $deposits = $this->deposits($stripe);
        $deposit  = $this->seedHeld($leaseId, $hunter, $landowner, 20000);

        $svc   = $this->claims($deposits);
        $claim = $this->track($svc->file($this->lease($leaseId), (new User())->forceFill(['id' => $landowner]), 'property_damage', 18000, 'Damage.'));
        $svc->review($claim->id, DamageClaimService::DECISION_APPROVE, 18000, (string) Str::uuid());
        $result = $svc->forfeitDepositForApproved($claim->id, (string) Str::uuid());

        $this->assertSame('paid', $result->status);
        $this->assertSame($deposit->id, $result->security_deposit_id);

        $fresh = SecurityDeposit::find($deposit->id);
        $this->assertSame('held', $fresh->status); // a claim, not yet settled
        $this->assertSame(18000, (int) $fresh->forfeited_amount_cents);
        $this->assertSame('pending', $fresh->forfeit_trust_status);
        $this->assertSame('lessee', $fresh->forfeit_fault);
    }

    public function test_forfeit_requires_an_approved_claim(): void
    {
        $claim = $this->track($this->claims()->file(
            $this->lease((string) Str::uuid()), (new User())->forceFill(['id' => $this->makeUser('landowner')]),
            'property_damage', 18000, 'Damage.',
        ));

        $this->expectException(\RuntimeException::class);
        $this->claims()->forfeitDepositForApproved($claim->id, (string) Str::uuid());
    }
}
