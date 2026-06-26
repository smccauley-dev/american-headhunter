<?php

namespace Tests\Feature\Filament;

use App\Filament\Admin\Pages\ForfeitureOversight;
use App\Models\Billing\SecurityDeposit;
use App\Models\Identity\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Forfeiture Oversight report mounts for a billing-capable admin and renders
 * the landowner-abuse signal: a landowner over the review threshold shows as
 * "Review" with their name resolved cross-DB. Real connections — rows are cleaned.
 */
class ForfeitureOversightTest extends TestCase
{
    private string $actorId;
    private string $scammerId;
    /** @var array<int,string> */ private array $depositIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $superAdminRoleId = (string) DB::connection('identity')->table('roles')->where('name', 'super_admin')->value('id');
        $this->actorId   = $this->makeUser('forfeit-admin@test.invalid', 'staff', $superAdminRoleId, 'Forf', 'Admin');
        $this->scammerId = $this->makeUser('forfeit-scammer@test.invalid', 'landowner', null, 'Sketchy', 'Owner');

        // 4 forfeited + 1 released of 5 concluded = 80% → over the review threshold.
        foreach (['forfeited', 'forfeited', 'forfeited', 'forfeited', 'released'] as $status) {
            $this->seedResolved($this->scammerId, $status, $status === 'forfeited' ? 5000 : 0);
        }
    }

    protected function tearDown(): void
    {
        DB::connection('billing')->table('security_deposits')->whereIn('id', $this->depositIds)->delete();
        foreach ([$this->actorId, $this->scammerId] as $id) {
            DB::connection('identity')->table('user_profiles')->where('user_id', $id)->delete();
            DB::connection('identity')->table('user_roles')->where('user_id', $id)->delete();
            DB::connection('identity')->table('users')->where('id', $id)->delete();
        }
        DB::connection('identity')->disconnect();

        parent::tearDown();
    }

    private function makeUser(string $email, string $accountType, ?string $roleId, string $first, string $last): string
    {
        $id = (string) Str::uuid();
        DB::connection('identity')->table('users')->insert([
            'id'            => $id,
            'email'         => $email,
            'password_hash' => bcrypt('Password1!local'),
            'status'        => 'active',
            'account_type'  => $accountType,
            'email_verified_at' => now(),
            'trust_score'   => 100,
        ]);
        DB::connection('identity')->table('user_profiles')->insert([
            'user_id'    => $id,
            'first_name' => $first,
            'last_name'  => $last,
        ]);
        if ($roleId) {
            DB::connection('identity')->table('user_roles')->insert(['user_id' => $id, 'role_id' => $roleId]);
        }

        return $id;
    }

    private function seedResolved(string $payeeId, string $status, int $forfeitedCents): void
    {
        $deposit = SecurityDeposit::create([
            'lease_id'               => (string) Str::uuid(),
            'payer_user_id'          => (string) Str::uuid(),
            'payee_user_id'          => $payeeId,
            'amount_cents'           => 5000,
            'forfeited_amount_cents' => $forfeitedCents,
            'currency'               => 'USD',
            'status'                 => $status,
        ]);
        $this->depositIds[] = $deposit->id;
    }

    public function test_report_flags_an_abusive_landowner(): void
    {
        $this->actingAs(User::on('identity')->find($this->actorId), 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ForfeitureOversight::class)
            ->assertOk()
            ->assertSee('Sketchy Owner')
            ->assertSee('Review')
            ->assertSee('80%');
    }
}
