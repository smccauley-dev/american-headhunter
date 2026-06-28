<?php

namespace Tests\Feature\Filament;

use App\Filament\Admin\Resources\LeasePayments\Pages\ListLeasePayments;
use App\Filament\Admin\Resources\LeasePayments\Pages\ViewLeasePayment;
use App\Models\Billing\LeasePayment;
use App\Models\Identity\User;
use App\Services\Billing\LeasePaymentService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * Admin Lease Payments resource: a super-admin lists collected payments and the
 * View page's Refund action calls LeasePaymentService::refund. The service is
 * mocked (no Stripe round trip); the payment row is real on `billing`.
 */
class LeasePaymentResourceTest extends TestCase
{
    private string $actorId;
    private string $paymentId;

    protected function setUp(): void
    {
        parent::setUp();

        $superAdminRoleId = (string) DB::connection('identity')->table('roles')->where('name', 'super_admin')->value('id');
        $this->actorId = (string) Str::uuid();
        DB::connection('identity')->table('users')->insert([
            'id'                => $this->actorId,
            'email'             => "lp-actor-{$this->actorId}@test.invalid",
            'password_hash'     => bcrypt('Password1!local'),
            'status'            => 'active',
            'account_type'      => 'staff',
            'email_verified_at' => now(),
            'trust_score'       => 100,
        ]);
        DB::connection('identity')->table('user_profiles')->insert([
            'user_id'    => $this->actorId,
            'first_name' => 'Billing',
            'last_name'  => 'Admin',
        ]);
        DB::connection('identity')->table('user_roles')->insert(['user_id' => $this->actorId, 'role_id' => $superAdminRoleId]);

        $this->paymentId = (string) Str::uuid();
        DB::connection('billing')->table('lease_payments')->insert([
            'id'                       => $this->paymentId,
            'lease_id'                 => (string) Str::uuid(),
            'payer_user_id'            => (string) Str::uuid(),
            'payee_user_id'            => (string) Str::uuid(),
            'stripe_account_id'        => 'acct_lp_' . Str::random(8),
            'gross_cents'              => 100300,
            'surcharge_cents'          => 300,
            'application_fee_cents'    => 5300,
            'net_cents'                => 95000,
            'currency'                 => 'USD',
            'status'                   => 'collected',
            'stripe_payment_intent_id' => 'pi_lp_' . Str::random(12),
            'paid_at'                  => now(),
        ]);
    }

    protected function tearDown(): void
    {
        DB::connection('billing')->table('lease_payments')->where('id', $this->paymentId)->delete();
        DB::connection('identity')->table('user_profiles')->where('user_id', $this->actorId)->delete();
        DB::connection('identity')->table('user_roles')->where('user_id', $this->actorId)->delete();
        DB::connection('identity')->table('users')->where('id', $this->actorId)->delete();
        DB::connection('identity')->disconnect();
        parent::tearDown();
    }

    private function actAsSuperAdmin(): void
    {
        $this->actingAs(User::on('identity')->find($this->actorId), 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_admin_can_list_lease_payments(): void
    {
        $this->actAsSuperAdmin();

        Livewire::test(ListLeasePayments::class)
            ->assertOk()
            ->assertCanSeeTableRecords([LeasePayment::find($this->paymentId)]);
    }

    public function test_refund_action_calls_the_service(): void
    {
        $this->actAsSuperAdmin();

        $mock = Mockery::mock(LeasePaymentService::class);
        $mock->shouldReceive('refund')
            ->once()
            ->with(Mockery::type(LeasePayment::class), null, $this->actorId) // full refund → null amount
            ->andReturnUsing(function (LeasePayment $p) {
                $p->status = 'refunded';
                return $p;
            });
        $this->app->instance(LeasePaymentService::class, $mock);

        // Record-bound page: set the action's form data directly on mountedActions —
        // setActionData/callAction's data array is wiped by fillForm re-hydration here.
        Livewire::test(ViewLeasePayment::class, ['record' => $this->paymentId])
            ->mountAction('refund')
            ->set('mountedActions.0.data.amount', '1003.00') // full gross ($1003.00)
            ->callMountedAction()
            ->assertHasNoActionErrors();
    }
}
