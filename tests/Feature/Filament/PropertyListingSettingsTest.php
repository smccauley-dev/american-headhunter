<?php

namespace Tests\Feature\Filament;

use App\Filament\Admin\Pages\PropertyListingSettings;
use App\Models\Identity\User;
use App\Services\Platform\TenantService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Covers the Property Listings admin configurator: the Filament page persists
 * its `properties.*` settings via TenantService, and the public Find Land
 * controller exposes them to the page as a `config` prop with the original
 * hardcoded values as defaults.
 */
class PropertyListingSettingsTest extends TestCase
{
    private string $actorId;

    /** Setting keys this test touches — cleaned up in tearDown. */
    private const TOUCHED_KEYS = [
        'properties.cta_guest_url',
        'properties.filter_species_enabled',
        'properties.card_columns',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $roleId = (string) DB::connection('identity')->table('roles')->where('name', 'super_admin')->value('id');

        $this->actorId = (string) Str::uuid();
        DB::connection('identity')->table('users')->insert([
            'id'                => $this->actorId,
            'email'             => "prop-admin-{$this->actorId}@test.invalid",
            'password_hash'     => bcrypt('Password1!local'),
            'status'            => 'active',
            'account_type'      => 'staff',
            'email_verified_at' => now(),
            'trust_score'       => 100,
        ]);
        DB::connection('identity')->table('user_profiles')->insert([
            'user_id'    => $this->actorId,
            'first_name' => 'Prop',
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
        // Remove the rows this test wrote and drop their cached values so the
        // settings table returns to its pre-test (default) state.
        DB::connection('platform')->table('tenant_settings')
            ->whereIn('key', self::TOUCHED_KEYS)->delete();
        foreach (self::TOUCHED_KEYS as $key) {
            Cache::store('valkey')->forget("cfg:platform:{$key}");
        }

        DB::connection('identity')->table('user_roles')->where('user_id', $this->actorId)->delete();
        DB::connection('identity')->table('user_profiles')->where('user_id', $this->actorId)->delete();
        DB::connection('identity')->table('users')->where('id', $this->actorId)->delete();
        DB::connection('identity')->disconnect();

        parent::tearDown();
    }

    public function test_save_persists_settings_via_tenant_service(): void
    {
        Livewire::test(PropertyListingSettings::class)
            ->set('data.cta_guest_url', '/pricing')
            ->set('data.filter_species_enabled', false)
            ->set('data.card_columns', '3')
            ->call('save')
            ->assertHasNoFormErrors();

        $t = app(TenantService::class);
        $this->assertSame('/pricing', $t->getSetting('properties.cta_guest_url'));
        $this->assertSame('0', $t->getSetting('properties.filter_species_enabled'));
        $this->assertSame('3', $t->getSetting('properties.card_columns'));
    }

    public function test_save_rejects_external_cta_url(): void
    {
        Livewire::test(PropertyListingSettings::class)
            ->set('data.cta_guest_url', 'https://evil.example.com')
            ->call('save')
            ->assertHasFormErrors(['cta_guest_url']);
    }

    public function test_public_listings_page_exposes_config_with_defaults(): void
    {
        $this->get('/properties')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Public/Properties', false)
                ->where('config.cta_guest_url', '/get-started')
                ->where('config.hero_eyebrow', 'Find Land')
                ->where('config.card_columns', 2)
                ->where('config.filter_species_enabled', true));
    }
}
