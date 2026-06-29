<?php

namespace Tests\Feature\Filament;

use App\Filament\Admin\Resources\SecurityDeposits\Pages\ViewSecurityDeposit;
use App\Models\Billing\SecurityDeposit;
use App\Models\Identity\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The admin deposit View page surfaces the release processing fee once a release has
 * recorded one — in particular a 'deferred' fee (the platform couldn't debit the
 * landowner's Connect balance) is flagged as owed and uncollected so staff can act on
 * it. The section is hidden on a deposit that never recorded a fee.
 */
class SecurityDepositReleaseFeeViewTest extends TestCase
{
    private string $actorId;
    private array $depositIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $superAdminRoleId = (string) DB::connection('identity')->table('roles')->where('name', 'super_admin')->value('id');
        $this->actorId = (string) Str::uuid();
        DB::connection('identity')->table('users')->insert([
            'id' => $this->actorId, 'email' => "sd-actor-{$this->actorId}@test.invalid",
            'password_hash' => bcrypt('Password1!local'), 'status' => 'active',
            'account_type' => 'staff', 'email_verified_at' => now(), 'trust_score' => 100,
        ]);
        DB::connection('identity')->table('user_profiles')->insert([
            'user_id' => $this->actorId, 'first_name' => 'Billing', 'last_name' => 'Admin',
        ]);
        DB::connection('identity')->table('user_roles')->insert(['user_id' => $this->actorId, 'role_id' => $superAdminRoleId]);
    }

    protected function tearDown(): void
    {
        DB::connection('billing')->table('security_deposits')->whereIn('id', $this->depositIds)->delete();
        DB::connection('identity')->table('user_profiles')->where('user_id', $this->actorId)->delete();
        DB::connection('identity')->table('user_roles')->where('user_id', $this->actorId)->delete();
        DB::connection('identity')->table('users')->where('id', $this->actorId)->delete();
        DB::connection('identity')->disconnect();
        parent::tearDown();
    }

    private function deposit(array $overrides): SecurityDeposit
    {
        $deposit = SecurityDeposit::create(array_merge([
            'lease_id' => (string) Str::uuid(), 'payer_user_id' => (string) Str::uuid(),
            'payee_user_id' => (string) Str::uuid(), 'amount_cents' => 50000, 'currency' => 'USD',
            'status' => 'released', 'refunded_amount_cents' => 50000, 'released_at' => now(),
        ], $overrides));
        $this->depositIds[] = $deposit->id;

        return $deposit;
    }

    private function actAsSuperAdmin(): void
    {
        $this->actingAs(User::on('identity')->find($this->actorId), 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_view_surfaces_a_deferred_release_fee_as_owed(): void
    {
        $this->actAsSuperAdmin();
        $deposit = $this->deposit(['release_fee_cents' => 1480, 'release_fee_status' => 'deferred']);

        Livewire::test(ViewSecurityDeposit::class, ['record' => $deposit->id])
            ->assertOk()
            ->assertSee('Release Processing Fee')
            ->assertSee('$14.80')
            ->assertSee('Deferred')
            ->assertSee('charge it manually');
    }

    public function test_view_hides_the_section_when_no_fee_was_recorded(): void
    {
        $this->actAsSuperAdmin();
        $deposit = $this->deposit(['release_fee_status' => null]);

        Livewire::test(ViewSecurityDeposit::class, ['record' => $deposit->id])
            ->assertOk()
            ->assertDontSee('Release Processing Fee');
    }
}
