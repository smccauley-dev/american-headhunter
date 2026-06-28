<?php

namespace Tests\Feature\Filament;

use App\Filament\Admin\Resources\GameTypes\Pages\CreateGameType;
use App\Models\Identity\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * GameTypeResource create flow: a staff member pastes a complete <svg> and the
 * resource folds it down to inner markup + an extracted viewBox before storing
 * (applyIconNormalization), so GameIcon — which supplies its own <svg> wrapper —
 * renders it correctly.
 */
class GameTypeResourceTest extends TestCase
{
    private string $actorId;
    private string $code;

    protected function setUp(): void
    {
        parent::setUp();

        $this->code = 'test_gt_' . substr((string) Str::uuid(), 0, 8);

        $roleId = (string) DB::connection('identity')->table('roles')->where('name', 'super_admin')->value('id');

        $this->actorId = (string) Str::uuid();
        DB::connection('identity')->table('users')->insert([
            'id'                => $this->actorId,
            'email'             => "gt-admin-{$this->actorId}@test.invalid",
            'password_hash'     => bcrypt('Password1!local'),
            'status'            => 'active',
            'account_type'      => 'staff',
            'email_verified_at' => now(),
            'trust_score'       => 100,
        ]);
        DB::connection('identity')->table('user_profiles')->insert([
            'user_id'    => $this->actorId,
            'first_name' => 'GT',
            'last_name'  => 'Admin',
        ]);
        DB::connection('identity')->table('user_roles')->insert([
            'user_id' => $this->actorId,
            'role_id' => $roleId,
        ]);

        $this->actingAs(User::on('identity')->find($this->actorId), 'web');
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
    }

    protected function tearDown(): void
    {
        DB::connection('property')->table('game_types')->where('code', $this->code)->delete();
        app(\App\Services\Property\PropertyService::class)->forgetGameTypesCache();

        DB::connection('identity')->table('user_roles')->where('user_id', $this->actorId)->delete();
        DB::connection('identity')->table('user_profiles')->where('user_id', $this->actorId)->delete();
        DB::connection('identity')->table('users')->where('id', $this->actorId)->delete();

        foreach (['identity', 'property', 'property_read'] as $conn) {
            try { DB::connection($conn)->disconnect(); } catch (\Throwable) {}
        }

        parent::tearDown();
    }

    public function test_create_normalizes_pasted_full_svg_into_inner_markup_and_viewbox(): void
    {
        Livewire::test(CreateGameType::class)
            ->set('data.code', $this->code)
            ->set('data.label', 'Test Critter')
            ->set('data.default_availability', 'seasonal')
            ->set('data.sort_order', 500)
            ->set('data.is_active', true)
            ->set('data.icon_svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M9 9"/></svg>')
            ->set('data.icon_viewbox', '0 0 512 512')
            ->call('create')
            ->assertHasNoFormErrors();

        $row = DB::connection('property')->table('game_types')->where('code', $this->code)->first();

        $this->assertNotNull($row);
        $this->assertSame('<path d="M9 9"/>', $row->icon_svg);
        $this->assertSame('0 0 24 24', $row->icon_viewbox);
    }
}
