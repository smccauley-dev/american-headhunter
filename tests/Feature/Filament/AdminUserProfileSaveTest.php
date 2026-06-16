<?php

namespace Tests\Feature\Filament;

use App\Filament\Admin\Resources\Users\Pages\CreateAdminUser;
use App\Filament\Admin\Resources\Users\Pages\EditAdminUser;
use App\Models\Identity\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression for the silent name-save bug: the Edit/Create Admin User pages
 * unset first_name/last_name/password in mutateFormDataBeforeSave/Create, which
 * Filament runs BEFORE handleRecordUpdate/handleRecordCreation reads them — so
 * the profile name was never written (the form reported success but the name
 * stayed blank). Removing those strips lets the names persist.
 *
 * Note: this repo's tests run with APP_ENV=local (real OS env var in the
 * container; phpunit.xml's non-forced APP_ENV=testing does not override it), so
 * app()->runningUnitTests() is false and Filament's fillForm() test helper is a
 * no-op. We drive the form with plain Livewire ->set('data.*') instead.
 */
class AdminUserProfileSaveTest extends TestCase
{
    private string $actorId;
    private string $targetId;
    private string $createdId;
    private string $superAdminRoleId;
    private string $staffRoleId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdminRoleId = (string) DB::connection('identity')->table('roles')->where('name', 'super_admin')->value('id');
        $this->staffRoleId      = (string) DB::connection('identity')->table('roles')->where('name', 'staff')->value('id');

        $this->actorId  = $this->makeUser('actor@profilesave.test', 'Acting', 'Admin', $this->superAdminRoleId);
        $this->targetId = $this->makeUser('target@profilesave.test', 'Original', 'Name', $this->staffRoleId);
        $this->createdId = '';
    }

    protected function tearDown(): void
    {
        foreach (array_filter([$this->actorId, $this->targetId, $this->createdId]) as $id) {
            DB::connection('identity')->table('users')->where('id', $id)->delete();
        }
        DB::connection('identity')->disconnect();

        parent::tearDown();
    }

    private function makeUser(string $email, string $first, string $last, string $roleId): string
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
            'first_name' => $first,
            'last_name'  => $last,
        ]);
        DB::connection('identity')->table('user_roles')->insert([
            'user_id' => $id,
            'role_id' => $roleId,
        ]);

        return $id;
    }

    private function actAsSuperAdmin(): void
    {
        $actor = User::on('identity')->find($this->actorId);
        $this->actingAs($actor, 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_edit_persists_first_and_last_name(): void
    {
        $this->actAsSuperAdmin();

        Livewire::test(EditAdminUser::class, ['record' => $this->targetId])
            ->assertFormSet(['first_name' => 'Original', 'last_name' => 'Name'])
            ->set('data.first_name', 'Joe')
            ->set('data.last_name', 'Mama')
            ->call('save')
            ->assertHasNoFormErrors();

        $profile = DB::connection('identity')->table('user_profiles')->where('user_id', $this->targetId)->first();
        $this->assertSame('Joe', $profile->first_name, 'Edit must persist first_name (mutateFormDataBeforeSave previously dropped it).');
        $this->assertSame('Mama', $profile->last_name, 'Edit must persist last_name.');
    }

    public function test_create_persists_first_and_last_name(): void
    {
        $this->actAsSuperAdmin();

        $email = 'created@profilesave.test';

        Livewire::test(CreateAdminUser::class)
            ->set('data.first_name', 'Fresh')
            ->set('data.last_name', 'Recruit')
            ->set('data.email', $email)
            ->set('data.password', 'Password1!local')
            ->set('data.roles', [$this->staffRoleId])
            ->set('data.status', 'active')
            ->call('create')
            ->assertHasNoFormErrors();

        $this->createdId = (string) DB::connection('identity')->table('users')->where('email', $email)->value('id');
        $this->assertNotEmpty($this->createdId);

        $profile = DB::connection('identity')->table('user_profiles')->where('user_id', $this->createdId)->first();
        $this->assertSame('Fresh', $profile->first_name, 'Create must persist first_name.');
        $this->assertSame('Recruit', $profile->last_name, 'Create must persist last_name.');
    }
}
