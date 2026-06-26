<?php

namespace Tests\Feature\Filament;

use App\Filament\Admin\Resources\IncidentReports\Pages\ViewIncidentReport;
use App\Models\Identity\User;
use App\Models\Incidents\IncidentReport;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The admin incident-triage page drives the report status through IncidentService:
 * open → investigating → resolved → closed, capturing authority + resolution detail.
 * Real connections (admin runs as the BYPASSRLS system role).
 */
class IncidentReportManagementTest extends TestCase
{
    private string $actorId;
    private string $reporterId;
    /** @var array<int,string> */ private array $reportIds = [];
    /** @var array<int,string> */ private array $userIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $superAdminRoleId = (string) DB::connection('identity')->table('roles')->where('name', 'super_admin')->value('id');
        $this->actorId    = $this->makeUser('staff', $superAdminRoleId);
        $this->reporterId = $this->makeUser('hunter');
    }

    protected function tearDown(): void
    {
        if ($this->reportIds) {
            DB::connection('incidents')->table('incident_reports')->whereIn('id', $this->reportIds)->delete();
        }
        DB::connection('identity')->table('user_roles')->whereIn('user_id', $this->userIds)->delete();
        DB::connection('identity')->table('users')->whereIn('id', $this->userIds)->delete();

        parent::tearDown();
    }

    private function makeUser(string $accountType, ?string $roleId = null): string
    {
        $id = (string) Str::uuid();
        DB::connection('identity')->table('users')->insert([
            'id'            => $id,
            'email'         => "inc-{$accountType}-{$id}@test.invalid",
            'password_hash' => 'test-hash',
            'status'        => 'active',
            'account_type'  => $accountType,
            'trust_score'   => 50,
        ]);
        if ($roleId) {
            DB::connection('identity')->table('user_roles')->insert(['user_id' => $id, 'role_id' => $roleId]);
        }
        $this->userIds[] = $id;

        return $id;
    }

    private function seedReport(): IncidentReport
    {
        $report = IncidentReport::create([
            'property_id'      => (string) Str::uuid(),
            'reporter_user_id' => $this->reporterId,
            'incident_type'    => 'trespassing',
            'severity'         => 'moderate',
            'status'           => 'open',
            'occurred_at'      => now()->subDay(),
            'description'      => 'Unknown vehicle on the access road.',
        ]);
        $this->reportIds[] = $report->id;

        return $report;
    }

    private function actAsAdmin(): void
    {
        $this->actingAs(User::on('identity')->find($this->actorId), 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_investigate_then_resolve_then_close(): void
    {
        $report = $this->seedReport();
        $this->actAsAdmin();

        Livewire::test(ViewIncidentReport::class, ['record' => $report->id])
            ->mountAction('investigate')
            ->set('mountedActions.0.data.report_number', 'SO-2026-114')
            ->set('mountedActions.0.data.notified', true)
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $report->refresh();
        $this->assertSame('investigating', $report->status);
        $this->assertSame('SO-2026-114', $report->authority_report_number);
        $this->assertTrue($report->authorities_notified);
        $this->assertNull($report->resolved_at);

        Livewire::test(ViewIncidentReport::class, ['record' => $report->id])
            ->mountAction('resolve')
            ->set('mountedActions.0.data.notes', 'Sheriff identified the vehicle; trespasser warned.')
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $report->refresh();
        $this->assertSame('resolved', $report->status);
        $this->assertNotNull($report->resolved_at);
        $this->assertSame('Sheriff identified the vehicle; trespasser warned.', $report->resolution_notes);

        Livewire::test(ViewIncidentReport::class, ['record' => $report->id])
            ->callAction('close')
            ->assertHasNoActionErrors();

        $this->assertSame('closed', $report->refresh()->status);
    }

    public function test_resolve_requires_notes(): void
    {
        $report = $this->seedReport();
        $this->actAsAdmin();

        Livewire::test(ViewIncidentReport::class, ['record' => $report->id])
            ->mountAction('resolve')
            ->set('mountedActions.0.data.notes', '')
            ->callMountedAction()
            ->assertHasActionErrors(['notes']);

        $this->assertSame('open', $report->refresh()->status);
    }
}
