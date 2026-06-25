<?php

namespace Tests\Feature\Filament;

use App\Filament\Admin\Pages\Dashboard;
use App\Models\Identity\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The tabbed analytics dashboard mounts for an admin, reads DB 8 figures, and the
 * "Refresh now" action recomputes the snapshot without crashing.
 */
class DashboardAnalyticsTest extends TestCase
{
    private string $actorId;

    protected function setUp(): void
    {
        parent::setUp();

        try {
            DB::connection('analytics')->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('analytics connection unavailable: ' . $e->getMessage());
        }

        $roleId = (string) DB::connection('identity')->table('roles')->where('name', 'super_admin')->value('id');

        $this->actorId = (string) Str::uuid();
        DB::connection('identity')->table('users')->insert([
            'id'                => $this->actorId,
            'email'             => "dash-admin-{$this->actorId}@test.invalid",
            'password_hash'     => bcrypt('Password1!local'),
            'status'            => 'active',
            'account_type'      => 'staff',
            'email_verified_at' => now(),
            'trust_score'       => 100,
        ]);
        DB::connection('identity')->table('user_profiles')->insert([
            'user_id'    => $this->actorId,
            'first_name' => 'Dash',
            'last_name'  => 'Admin',
        ]);
        DB::connection('identity')->table('user_roles')->insert([
            'user_id' => $this->actorId,
            'role_id' => $roleId,
        ]);

        $this->actingAs(User::on('identity')->find($this->actorId), 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function tearDown(): void
    {
        DB::connection('identity')->table('user_roles')->where('user_id', $this->actorId)->delete();
        DB::connection('identity')->table('user_profiles')->where('user_id', $this->actorId)->delete();
        DB::connection('identity')->table('users')->where('id', $this->actorId)->delete();
        DB::connection('identity')->disconnect();

        parent::tearDown();
    }

    public function test_dashboard_mounts_with_analytics_for_a_billing_admin(): void
    {
        $component = Livewire::test(Dashboard::class)->assertOk();

        // super_admin can view billing, so the Revenue tab data is loaded.
        $this->assertTrue($component->get('canViewRevenue'));
        $this->assertArrayHasKey('total_users', $component->get('counts'));
    }

    public function test_refresh_now_recomputes_without_crashing(): void
    {
        Livewire::test(Dashboard::class)
            ->callAction(TestAction::make('refresh'))
            ->assertOk()
            ->assertHasNoActionErrors();
    }
}
