<?php

namespace Tests\Feature\Filament;

use App\Filament\Admin\Resources\LeaseDisputes\Pages\ViewLeaseDispute;
use App\Models\Billing\SecurityDeposit;
use App\Models\Identity\User;
use App\Models\Incidents\LeaseDispute;
use App\Models\Lease\Lease;
use App\Services\Billing\SecurityDepositService;
use App\Services\Billing\StripeService;
use App\Services\Incidents\DisputeService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use Stripe\Refund;
use Tests\TestCase;

/**
 * The admin adjudication page drives the dispute outcome through DisputeService:
 * Uphold applies the hunter's penalty, Overturn refunds and docks the landowner.
 * Real connections (admin runs as the BYPASSRLS system role); Stripe is mocked.
 */
class LeaseDisputeAdjudicationTest extends TestCase
{
    private string $actorId;
    private string $hunterId;
    private string $landownerId;
    private string $leaseId;
    /** @var array<int,string> */ private array $depositIds = [];
    /** @var array<int,string> */ private array $disputeIds = [];
    /** @var array<int,string> */ private array $userIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $superAdminRoleId = (string) DB::connection('identity')->table('roles')->where('name', 'super_admin')->value('id');
        $this->actorId     = $this->makeUser('staff', 50, $superAdminRoleId);
        $this->hunterId    = $this->makeUser('hunter', 80);
        $this->landownerId = $this->makeUser('landowner', 60);
        $this->leaseId     = (string) Str::uuid();
    }

    protected function tearDown(): void
    {
        if ($this->disputeIds) {
            DB::connection('incidents')->table('lease_disputes')->whereIn('id', $this->disputeIds)->delete();
        }
        if ($this->depositIds) {
            DB::connection('billing')->table('security_deposits')->whereIn('id', $this->depositIds)->delete();
        }
        DB::connection('identity')->table('trust_score_events')->whereIn('user_id', $this->userIds)->delete();
        DB::connection('identity')->table('user_roles')->whereIn('user_id', $this->userIds)->delete();
        DB::connection('identity')->table('users')->whereIn('id', $this->userIds)->delete();

        parent::tearDown();
    }

    private function makeUser(string $accountType, int $trustScore, ?string $roleId = null): string
    {
        $id = (string) Str::uuid();
        DB::connection('identity')->table('users')->insert([
            'id'            => $id,
            'email'         => "adj-{$accountType}-{$id}@test.invalid",
            'password_hash' => 'test-hash',
            'status'        => 'active',
            'account_type'  => $accountType,
            'trust_score'   => $trustScore,
        ]);
        if ($roleId) {
            DB::connection('identity')->table('user_roles')->insert(['user_id' => $id, 'role_id' => $roleId]);
        }
        $this->userIds[] = $id;

        return $id;
    }

    /** Seed a held deposit, park a pending hunter-fault forfeiture, open a contest. */
    private function openDispute(?StripeService $stripe = null): LeaseDispute
    {
        if ($stripe) {
            $this->app->instance(StripeService::class, $stripe);
        }

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
        $this->depositIds[] = $deposit->id;

        app(SecurityDepositService::class)->forfeit(
            $deposit->id, 5000, 'Cabin damage', $this->actorId, SecurityDepositService::FAULT_LESSEE, 'property_damage',
        );

        $dispute = app(DisputeService::class)->fileForfeitureContest(
            (new Lease())->forceFill(['id' => $this->leaseId]),
            (new User())->forceFill(['id' => $this->hunterId]),
            'Damage was pre-existing.',
        );
        $this->disputeIds[] = $dispute->id;

        return $dispute;
    }

    private function actAsAdmin(): void
    {
        $this->actingAs(User::on('identity')->find($this->actorId), 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_uphold_applies_the_hunter_penalty(): void
    {
        $stripe = Mockery::mock(StripeService::class);
        $stripe->shouldNotReceive('refundPaymentIntent');
        $dispute = $this->openDispute($stripe);

        $this->actAsAdmin();

        Livewire::test(ViewLeaseDispute::class, ['record' => $dispute->id])
            ->callAction('uphold')
            ->assertHasNoActionErrors();

        $this->assertSame('resolved', LeaseDispute::find($dispute->id)->status);
        $this->assertSame('applied', SecurityDeposit::find($this->depositIds[0])->forfeit_trust_status);
        $this->assertSame(70, (int) DB::connection('identity')->table('users')->where('id', $this->hunterId)->value('trust_score'));
    }

    public function test_overturn_refunds_and_docks_the_landowner(): void
    {
        $stripe = Mockery::mock(StripeService::class);
        $stripe->shouldReceive('refundPaymentIntent')->once()->andReturn(Refund::constructFrom(['id' => 're_adj']));
        $dispute = $this->openDispute($stripe);

        $this->actAsAdmin();

        Livewire::test(ViewLeaseDispute::class, ['record' => $dispute->id])
            ->callAction('overturn', ['credit_initiator' => false])
            ->assertHasNoActionErrors();

        $this->assertSame('released', SecurityDeposit::find($this->depositIds[0])->status);
        $this->assertSame('waived', SecurityDeposit::find($this->depositIds[0])->forfeit_trust_status);
        $this->assertSame(50, (int) DB::connection('identity')->table('users')->where('id', $this->landownerId)->value('trust_score'));
    }
}
