<?php

namespace Tests\Feature\Filament;

use App\Filament\Admin\Resources\IncidentReports\Pages\ViewIncidentReport;
use App\Models\Identity\User;
use App\Models\Incidents\IncidentReport;
use Filament\Actions\Testing\TestAction;
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
            'incident_items'   => [['type' => 'trespassing', 'severity' => 'moderate', 'occurred_at' => now()->subDay()->toIso8601String()]],
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

    public function test_edit_details_updates_record_and_writes_audit_diff(): void
    {
        $report = $this->seedReport();
        $this->actAsAdmin();

        // The admin reclassifies it as TWO line items — property damage and a medical injury.
        // Edit Details now lives in the Incident section header.
        Livewire::test(ViewIncidentReport::class, ['record' => $report->id])
            ->mountAction(TestAction::make('edit_details')->schemaComponent('incident-section'))
            ->set('mountedActions.0.data.items', [
                ['type' => 'property_damage', 'severity' => 'serious', 'occurred_at' => now()->subDay()->format('Y-m-d\TH:i')],
                ['type' => 'medical', 'severity' => 'moderate', 'occurred_at' => now()->subDay()->format('Y-m-d\TH:i')],
            ])
            ->set('mountedActions.0.data.description', 'Gate lock cut; corn feeder damaged and a hunter twisted an ankle.')
            ->set('mountedActions.0.data.injuries_reported', true)
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $report->refresh();
        // Lead = first item; severity = worst across the items.
        $this->assertSame('property_damage', $report->incident_type);
        $this->assertSame('serious', $report->severity);
        $this->assertCount(2, (array) $report->incident_items);
        $this->assertSame('Gate lock cut; corn feeder damaged and a hunter twisted an ankle.', $report->description);
        $this->assertTrue($report->injuries_reported);
        // Untouched fields are left alone.
        $this->assertSame('open', $report->status);

        $audit = DB::connection('audit')->table('audit_log')
            ->where('table_name', 'incident_reports')
            ->where('record_id', $report->id)
            ->where('event_type', 'incident_report.updated')
            ->first();

        $this->assertNotNull($audit, 'an edit should write an audit event');
        $this->assertSame($this->actorId, $audit->user_id, 'the audit must attribute the change to the admin');

        $old = json_decode($audit->old_values, true);
        $new = json_decode($audit->new_values, true);
        $this->assertSame('trespassing', $old['incident_items'][0]['type']);
        $this->assertCount(2, $new['incident_items']);
    }

    public function test_edit_details_captures_involved_parties_with_a_minor_flag(): void
    {
        $report = $this->seedReport();
        $this->actAsAdmin();

        Livewire::test(ViewIncidentReport::class, ['record' => $report->id])
            ->mountAction(TestAction::make('edit_details')->schemaComponent('incident-section'))
            ->set('mountedActions.0.data.parties', [
                ['full_name' => 'Hank Hill', 'is_minor' => false],
                ['full_name' => 'Bobby Hill', 'is_minor' => true],
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $report->refresh();
        $this->assertCount(2, (array) $report->parties_involved);
        $this->assertSame('Bobby Hill', $report->parties_involved[1]['full_name']);
        $this->assertTrue($report->parties_involved[1]['is_minor']);

        // The Parties Involved section lists the names and the minor marker.
        Livewire::test(ViewIncidentReport::class, ['record' => $report->id])
            ->assertOk()
            ->assertSee('Bobby Hill')
            ->assertSee('UNDER 18');
    }

    public function test_add_note_appends_an_admin_only_investigation_note(): void
    {
        $report = $this->seedReport();
        $this->actAsAdmin();

        // Add Note now lives in the Investigation Notes section header.
        Livewire::test(ViewIncidentReport::class, ['record' => $report->id])
            ->mountAction(TestAction::make('add_note')->schemaComponent('investigation-notes-section'))
            ->set('mountedActions.0.data.note', 'Spoke with the landowner; reviewing trail-cam footage.')
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $note = DB::connection('incidents')->table('incident_admin_notes')
            ->where('incident_report_id', $report->id)->first();

        $this->assertNotNull($note, 'the note should be persisted');
        $this->assertSame('Spoke with the landowner; reviewing trail-cam footage.', $note->note);
        $this->assertSame($this->actorId, $note->author_user_id);

        // The note renders in the (admin-only) Investigation Notes section.
        Livewire::test(ViewIncidentReport::class, ['record' => $report->id])
            ->assertOk()
            ->assertSee('reviewing trail-cam footage');
    }

    public function test_view_page_renders_change_history_after_an_item_diff(): void
    {
        $report = $this->seedReport();
        $this->actAsAdmin();

        // Produce an audit event whose old/new values contain the incident_items array.
        Livewire::test(ViewIncidentReport::class, ['record' => $report->id])
            ->mountAction(TestAction::make('edit_details')->schemaComponent('incident-section'))
            ->set('mountedActions.0.data.items', [
                ['type' => 'property_damage', 'severity' => 'serious', 'occurred_at' => now()->subDay()->format('Y-m-d\TH:i')],
                ['type' => 'medical', 'severity' => 'moderate', 'occurred_at' => now()->subDay()->format('Y-m-d\TH:i')],
            ])
            ->set('mountedActions.0.data.description', 'Gate lock cut; corn feeder damaged.')
            ->callMountedAction()
            ->assertHasNoActionErrors();

        // The Change History infolist renders the incident_items array without an
        // "Array to string conversion" error.
        Livewire::test(ViewIncidentReport::class, ['record' => $report->id])
            ->assertOk()
            ->assertSee('Property Damage');
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
