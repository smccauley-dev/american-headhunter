<?php

namespace Tests\Feature\Filament;

use App\Filament\Admin\Pages\NavigationSettings;
use App\Models\Identity\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression: the "Add Nav Item" section header action used to write a new key
 * straight into $this->data['nav_links'], so the Repeater never registered a
 * child schema for that key — the next render crashed in getItemLabel() with
 * "Call to a member function getStateSnapshot() on null". The action now adds
 * through the Repeater (rawState + getChildSchema()->fill()), mirroring
 * Repeater::getAddAction(), so the child schema exists and the render succeeds.
 */
class NavigationSettingsAddItemTest extends TestCase
{
    private string $actorId;

    protected function setUp(): void
    {
        parent::setUp();

        $roleId = (string) DB::connection('identity')->table('roles')->where('name', 'super_admin')->value('id');

        $this->actorId = (string) Str::uuid();
        DB::connection('identity')->table('users')->insert([
            'id'                => $this->actorId,
            'email'             => "nav-admin-{$this->actorId}@test.invalid",
            'password_hash'     => bcrypt('Password1!local'),
            'status'            => 'active',
            'account_type'      => 'staff',
            'email_verified_at' => now(),
            'trust_score'       => 100,
        ]);
        DB::connection('identity')->table('user_profiles')->insert([
            'user_id'    => $this->actorId,
            'first_name' => 'Nav',
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

    public function test_add_nav_item_appends_a_row_without_crashing(): void
    {
        $component = Livewire::test(NavigationSettings::class);
        $before    = count($component->get('data.nav_links') ?? []);

        // Mirrors the failing front-end call: mountAction('add_nav_item', [], {schemaComponent: 'form'}).
        $component
            ->callAction(TestAction::make('add_nav_item')->schemaComponent(true))
            ->assertOk()
            ->assertHasNoActionErrors();

        $after = count($component->get('data.nav_links') ?? []);
        $this->assertSame($before + 1, $after, 'the action appends exactly one nav_links row');
    }
}
