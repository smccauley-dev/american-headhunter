<?php

namespace Tests\Feature\Filament;

use App\Filament\Admin\Resources\Applications\Pages\ViewLeaseApplication;
use App\Models\Identity\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The admin application view surfaces the same landowner payment picture as the
 * member-facing pages: once a lease exists with a collected lease payment, the
 * "Payment Status" section renders the hunter's gross and the landowner's net.
 * Real connections — rows are cleaned in teardown.
 */
class LeaseApplicationPaymentStatusTest extends TestCase
{
    private string $adminId;
    private string $applicationId;
    private string $leaseId;
    private string $paymentId;

    protected function setUp(): void
    {
        parent::setUp();

        $superAdminRoleId = (string) DB::connection('identity')->table('roles')->where('name', 'super_admin')->value('id');
        $this->adminId = $this->makeAdmin('lease-pay-admin@test.invalid', $superAdminRoleId);

        $this->applicationId = (string) Str::uuid();
        $this->leaseId       = (string) Str::uuid();
        $this->paymentId     = (string) Str::uuid();

        DB::connection('lease')->table('lease_applications')->insert([
            'id'                => $this->applicationId,
            'listing_id'        => (string) Str::uuid(),
            'applicant_user_id' => (string) Str::uuid(),
            'application_type'  => 'individual',
            'status'            => 'approved',
            'desired_hunters'   => 1,
        ]);

        DB::connection('lease')->table('leases')->insert([
            'id'             => $this->leaseId,
            'application_id' => $this->applicationId,
            'property_id'    => (string) Str::uuid(),
            'listing_id'     => (string) Str::uuid(),
            'lessee_user_id' => (string) Str::uuid(),
            'lessor_user_id' => (string) Str::uuid(),
            'status'         => 'active',
            'start_date'     => '2026-09-01',
            'end_date'       => '2027-08-31',
            'total_price'    => 1000.00,
        ]);

        // $315 gross / $20 fee / $280 net, collected — leaves $700 outstanding.
        DB::connection('billing')->table('lease_payments')->insert([
            'id'                       => $this->paymentId,
            'lease_id'                 => $this->leaseId,
            'payer_user_id'            => (string) Str::uuid(),
            'payee_user_id'            => (string) Str::uuid(),
            'stripe_account_id'        => 'acct_' . Str::random(8),
            'gross_cents'              => 31500,
            'surcharge_cents'          => 1500,
            'application_fee_cents'    => 2000,
            'net_cents'                => 28000,
            'currency'                 => 'USD',
            'status'                   => 'collected',
            'stripe_payment_intent_id' => 'pi_' . Str::random(12),
            'paid_at'                  => now(),
        ]);
    }

    protected function tearDown(): void
    {
        DB::connection('billing')->table('lease_payments')->where('id', $this->paymentId)->delete();
        DB::connection('lease')->table('leases')->where('id', $this->leaseId)->delete();
        DB::connection('lease')->table('lease_applications')->where('id', $this->applicationId)->delete();
        DB::connection('identity')->table('user_profiles')->where('user_id', $this->adminId)->delete();
        DB::connection('identity')->table('user_roles')->where('user_id', $this->adminId)->delete();
        DB::connection('identity')->table('users')->where('id', $this->adminId)->delete();

        parent::tearDown();
    }

    private function makeAdmin(string $email, string $roleId): string
    {
        $id = (string) Str::uuid();
        DB::connection('identity')->table('users')->insert([
            'id'                => $id,
            'email'             => $email,
            'password_hash'     => bcrypt('Password1!local'),
            'status'            => 'active',
            'account_type'      => 'staff',
            'email_verified_at' => now(),
            'trust_score'       => 100,
        ]);
        DB::connection('identity')->table('user_profiles')->insert([
            'user_id'    => $id,
            'first_name' => 'Lease',
            'last_name'  => 'Admin',
        ]);
        DB::connection('identity')->table('user_roles')->insert(['user_id' => $id, 'role_id' => $roleId]);

        return $id;
    }

    public function test_payment_status_section_shows_gross_and_net(): void
    {
        $this->actingAs(User::on('identity')->find($this->adminId), 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ViewLeaseApplication::class, ['record' => $this->applicationId])
            ->assertOk()
            ->assertSee('Payment Status')
            ->assertSee('Net Received to Landowner')
            ->assertSee('$315.00')   // hunter paid (gross)
            ->assertSee('$280.00')   // net to landowner
            ->assertSee('$700.00');  // outstanding balance
    }
}
