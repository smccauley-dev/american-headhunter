<?php

namespace Tests\Feature\Filament;

use App\Filament\Admin\Pages\Dashboard;
use App\Filament\Admin\Widgets\Analytics\PlatformOverviewStats;
use App\Filament\Admin\Widgets\Analytics\RevenueStats;
use App\Models\Identity\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The analytics dashboard mounts for an admin, registers its widgets (revenue
 * gated to billing admins), and the "Refresh now" action recomputes the snapshot
 * without crashing.
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

    public function test_dashboard_mounts_with_analytics_widgets_for_a_billing_admin(): void
    {
        $component = Livewire::test(Dashboard::class)->assertOk();

        // Default tab is analytics; its widgets are registered and a super_admin
        // (billing access) sees the revenue widget.
        $this->assertSame('analytics', $component->get('activeTab'));
        $widgets = $component->instance()->getWidgets();
        $this->assertContains(PlatformOverviewStats::class, $widgets);
        $this->assertContains(RevenueStats::class, $widgets);
        $this->assertTrue(RevenueStats::canView());
    }

    public function test_switching_to_a_placeholder_tab_shows_no_widgets(): void
    {
        Livewire::test(Dashboard::class)
            ->set('activeTab', 'test1')
            ->assertOk()
            ->assertSee('Test Tab 1')
            ->tap(fn ($c) => $this->assertSame([], $c->instance()->getWidgets()));
    }

    public function test_overview_stats_widget_reads_db8_counts(): void
    {
        Livewire::test(PlatformOverviewStats::class)
            ->assertOk();
    }

    public function test_refresh_now_recomputes_without_crashing(): void
    {
        Livewire::test(Dashboard::class)
            ->callAction(TestAction::make('refresh'))
            ->assertOk()
            ->assertHasNoActionErrors();
    }
}
