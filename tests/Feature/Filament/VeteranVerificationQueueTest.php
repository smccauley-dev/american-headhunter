<?php

namespace Tests\Feature\Filament;

use App\Filament\Admin\Widgets\VeteranVerificationsTable;
use App\Models\Identity\User;
use App\Models\Identity\VeteranVerification;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Slice 4: the admin review queue. Mounts the veteran queue as a super-admin,
 * confirms a pending record is listed, and drives the approve table action —
 * which must run ServiceVerificationService::approve (flip the flag + grant the
 * configured promotion). Real connections, so rows are force-cleaned.
 */
class VeteranVerificationQueueTest extends TestCase
{
    private string $actorId;
    private string $applicantId;
    private string $recordId;

    protected function setUp(): void
    {
        parent::setUp();

        $superAdminRoleId = (string) DB::connection('identity')->table('roles')->where('name', 'super_admin')->value('id');
        $this->actorId     = $this->makeUser('queue-actor@vet.test', 'staff', $superAdminRoleId);
        $this->applicantId = $this->makeUser('queue-applicant@vet.test', 'hunter', null);

        $this->recordId = (string) Str::uuid();
        DB::connection('identity')->table('veteran_verifications')->insert([
            'id'      => $this->recordId,
            'user_id' => $this->applicantId,
            'method'  => 'dd214_upload',
            'status'  => 'pending',
        ]);
    }

    protected function tearDown(): void
    {
        DB::connection('billing')->table('promotion_claims')->where('user_id', $this->applicantId)->delete();
        DB::connection('identity')->table('veteran_verifications')->where('user_id', $this->applicantId)->delete();
        foreach ([$this->actorId, $this->applicantId] as $id) {
            DB::connection('identity')->table('user_profiles')->where('user_id', $id)->delete();
            DB::connection('identity')->table('user_roles')->where('user_id', $id)->delete();
            DB::connection('identity')->table('users')->where('id', $id)->delete();
        }
        DB::connection('identity')->disconnect();

        parent::tearDown();
    }

    private function makeUser(string $email, string $accountType, ?string $roleId): string
    {
        $id = (string) Str::uuid();
        DB::connection('identity')->table('users')->insert([
            'id'                => $id,
            'email'             => $email,
            'password_hash'     => bcrypt('Password1!local'),
            'status'            => 'active',
            'account_type'      => $accountType,
            'email_verified_at' => now(),
            'trust_score'       => 100,
        ]);
        DB::connection('identity')->table('user_profiles')->insert([
            'user_id'    => $id,
            'first_name' => 'Queue',
            'last_name'  => 'Tester',
        ]);
        if ($roleId) {
            DB::connection('identity')->table('user_roles')->insert(['user_id' => $id, 'role_id' => $roleId]);
        }

        return $id;
    }

    private function actAsSuperAdmin(): void
    {
        $this->actingAs(User::on('identity')->find($this->actorId), 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_queue_lists_pending_and_approve_action_grants_benefit(): void
    {
        $this->actAsSuperAdmin();

        $record = VeteranVerification::on('identity')->find($this->recordId);

        Livewire::test(VeteranVerificationsTable::class)
            ->assertCanSeeTableRecords([$record])
            ->callTableAction('approve', $record)
            ->assertHasNoTableActionErrors();

        $this->assertSame('approved', VeteranVerification::on('identity')->find($this->recordId)->status);
        $this->assertTrue((bool) User::on('identity')->find($this->applicantId)->is_veteran);
        $this->assertSame(
            1,
            DB::connection('billing')->table('promotion_claims')->where('user_id', $this->applicantId)->count(),
            'approving from the queue grants the configured veteran promotion',
        );
    }

    public function test_consolidated_page_mounts_with_both_verification_sections(): void
    {
        $this->actAsSuperAdmin();

        // The page mounts cleanly (catches import/wiring errors); its two
        // section tables are lazy header widgets loaded over separate Livewire
        // requests, so we assert they're wired in rather than rendered inline.
        // Each widget's table render is covered above and in its own test.
        $component = Livewire::test(\App\Filament\Admin\Pages\UserVerifications::class)
            ->assertOk();

        $method = new \ReflectionMethod(\App\Filament\Admin\Pages\UserVerifications::class, 'getHeaderWidgets');
        $method->setAccessible(true);
        $widgets = $method->invoke($component->instance());

        $this->assertContains(\App\Filament\Admin\Widgets\VeteranVerificationsTable::class, $widgets);
        $this->assertContains(\App\Filament\Admin\Widgets\FirstResponderVerificationsTable::class, $widgets);
    }
}
