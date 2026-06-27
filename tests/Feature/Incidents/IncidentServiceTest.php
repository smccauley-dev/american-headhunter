<?php

namespace Tests\Feature\Incidents;

use App\Models\Identity\User;
use App\Models\Incidents\IncidentReport;
use App\Models\Lease\Lease;
use App\Services\Audit\AuditService;
use App\Services\Documents\DocumentService;
use App\Services\Incidents\IncidentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * DB 10 incident-report intake & triage. Report rows are real on `incidents`
 * (owner role bypasses RLS in tests). Cross-DB references (property/lease/reporter)
 * are bare UUIDs assembled in the service layer.
 */
class IncidentServiceTest extends TestCase
{
    /** @var array<int,string> */ private array $reportIds = [];

    protected function tearDown(): void
    {
        if ($this->reportIds) {
            DB::connection('incidents')->table('incident_reports')->whereIn('id', $this->reportIds)->delete();
        }
        parent::tearDown();
    }

    private function service(): IncidentService
    {
        return new IncidentService(
            app(DocumentService::class),
            app(AuditService::class),
        );
    }

    private function lease(string $lesseeId, string $lessorId, ?string $propertyId = null): Lease
    {
        return (new Lease())->forceFill([
            'id'              => (string) Str::uuid(),
            'property_id'     => $propertyId ?? (string) Str::uuid(),
            'lessee_user_id'  => $lesseeId,
            'lessor_user_id'  => $lessorId,
        ]);
    }

    private function user(string $id): User
    {
        return (new User())->forceFill(['id' => $id]);
    }

    /** @param array<string,mixed> $overrides */
    private function data(array $overrides = []): array
    {
        return array_merge([
            'incident_type' => 'trespassing',
            'severity'      => 'moderate',
            'occurred_at'   => now()->subDay(),
            'description'   => 'Unknown vehicle parked on the access road overnight.',
        ], $overrides);
    }

    private function track(IncidentReport $r): IncidentReport
    {
        $this->reportIds[] = $r->id;

        return $r;
    }

    public function test_file_creates_an_open_report_for_the_lessee(): void
    {
        $lesseeId = (string) Str::uuid();
        $lease    = $this->lease($lesseeId, (string) Str::uuid());

        $report = $this->track($this->service()->file($lease, $this->user($lesseeId), $this->data([
            'location_description' => 'North gate',
            'injuries_reported'    => true,
        ])));

        $this->assertSame('open', $report->status);
        $this->assertSame('trespassing', $report->incident_type);
        $this->assertSame($lease->property_id, $report->property_id);
        $this->assertSame($lease->id, $report->lease_id);
        $this->assertSame($lesseeId, $report->reporter_user_id);
        $this->assertTrue($report->injuries_reported);
        $this->assertSame('North gate', $report->location_description);
    }

    public function test_file_allows_the_lessor_to_report(): void
    {
        $lessorId = (string) Str::uuid();
        $lease    = $this->lease((string) Str::uuid(), $lessorId);

        $report = $this->track($this->service()->file($lease, $this->user($lessorId), $this->data()));

        $this->assertSame($lessorId, $report->reporter_user_id);
    }

    public function test_file_rejects_a_non_party_reporter(): void
    {
        $lease = $this->lease((string) Str::uuid(), (string) Str::uuid());

        $this->expectException(\RuntimeException::class);
        $this->service()->file($lease, $this->user((string) Str::uuid()), $this->data());
    }

    public function test_update_status_advances_through_the_workflow(): void
    {
        $lesseeId = (string) Str::uuid();
        $lease    = $this->lease($lesseeId, (string) Str::uuid());
        $report   = $this->track($this->service()->file($lease, $this->user($lesseeId), $this->data()));

        $svc   = $this->service();
        $actor = (string) Str::uuid();

        $investigating = $svc->updateStatus($report->id, IncidentService::STATUS_INVESTIGATING, $actor, ['authorities_notified' => true]);
        $this->assertSame('investigating', $investigating->status);
        $this->assertTrue($investigating->authorities_notified);
        $this->assertNull($investigating->resolved_at);

        $resolved = $svc->updateStatus($report->id, IncidentService::STATUS_RESOLVED, $actor, ['resolution_notes' => 'Owner identified the vehicle; no further action.']);
        $this->assertSame('resolved', $resolved->status);
        $this->assertNotNull($resolved->resolved_at);
        $this->assertSame('Owner identified the vehicle; no further action.', $resolved->resolution_notes);

        $closed = $svc->updateStatus($report->id, IncidentService::STATUS_CLOSED, $actor);
        $this->assertSame('closed', $closed->status);
        $this->assertNotNull($closed->resolved_at);
    }

    public function test_update_status_rejects_an_illegal_transition(): void
    {
        $lesseeId = (string) Str::uuid();
        $lease    = $this->lease($lesseeId, (string) Str::uuid());
        $report   = $this->track($this->service()->file($lease, $this->user($lesseeId), $this->data()));

        $this->service()->updateStatus($report->id, IncidentService::STATUS_CLOSED, (string) Str::uuid());

        // closed is terminal — cannot reopen to investigating.
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->updateStatus($report->id, IncidentService::STATUS_INVESTIGATING, (string) Str::uuid());
    }

    public function test_file_assigns_a_sequential_incident_number_per_listing_scope(): void
    {
        $lesseeId   = (string) Str::uuid();
        $propertyId = (string) Str::uuid();
        $lease      = $this->lease($lesseeId, (string) Str::uuid(), $propertyId);

        // No listing exists for this random property, so the scope falls back to the
        // property id; both incidents share that scope and number sequentially.
        $first  = $this->track($this->service()->file($lease, $this->user($lesseeId), $this->data()));
        $second = $this->track($this->service()->file($lease, $this->user($lesseeId), $this->data()));

        $prefix = 'IR-' . strtoupper(substr($propertyId, 0, 8)) . '-';
        $this->assertSame($prefix . '01', $first->incident_number);
        $this->assertSame($prefix . '02', $second->incident_number);
    }

    public function test_update_details_appends_evidence_without_removing_existing(): void
    {
        $lesseeId = (string) Str::uuid();
        $lease    = $this->lease($lesseeId, (string) Str::uuid());
        $existing = (string) Str::uuid();
        $report   = $this->track($this->service()->file($lease, $this->user($lesseeId), $this->data(), [$existing]));

        $added = (string) Str::uuid();
        $updated = $this->service()->updateDetails($report->id, [], $lesseeId, [$existing, $added]);

        // The existing photo is preserved and the new one appended — nothing removed.
        $this->assertSame([$existing, $added], $updated->evidence_document_ids);

        $evidenceAudit = DB::connection('audit')->table('audit_log')
            ->where('table_name', 'incident_reports')
            ->where('record_id', $report->id)
            ->where('event_type', 'incident_report.evidence_added')
            ->first();
        $this->assertNotNull($evidenceAudit, 'appending a photo should be audited');
        $this->assertSame($lesseeId, $evidenceAudit->user_id);
    }

    public function test_file_with_multiple_items_derives_worst_severity_and_earliest_time(): void
    {
        $lesseeId = (string) Str::uuid();
        $lease    = $this->lease($lesseeId, (string) Str::uuid());

        $earlier = now()->subDays(2);
        $later   = now()->subDay();

        $report = $this->track($this->service()->file($lease, $this->user($lesseeId), $this->data([
            'items' => [
                ['type' => 'fire', 'severity' => 'serious', 'occurred_at' => $later->copy()->format('Y-m-d\TH:i')],
                ['type' => 'medical', 'severity' => 'critical', 'occurred_at' => $earlier->copy()->format('Y-m-d\TH:i')],
            ],
        ])));

        // Two items stored verbatim; lead = first item's type; severity = worst;
        // occurred_at = earliest across the items.
        $this->assertCount(2, $report->incident_items);
        $this->assertSame('fire', $report->incident_type);
        $this->assertSame('critical', $report->severity);
        $this->assertSame($earlier->format('Y-m-d H:i'), $report->occurred_at->format('Y-m-d H:i'));
    }

    public function test_update_details_records_an_item_diff_attributed_to_the_actor(): void
    {
        $lesseeId = (string) Str::uuid();
        $lease    = $this->lease($lesseeId, (string) Str::uuid());
        $report   = $this->track($this->service()->file($lease, $this->user($lesseeId), $this->data()));

        $this->service()->updateDetails($report->id, [
            'items' => [
                ['type' => 'property_damage', 'severity' => 'serious', 'occurred_at' => now()->subDay()->format('Y-m-d\TH:i')],
                ['type' => 'medical', 'severity' => 'moderate', 'occurred_at' => now()->subDay()->format('Y-m-d\TH:i')],
            ],
            'description' => 'Corrected: gate was damaged and a hunter twisted an ankle.',
        ], $lesseeId);

        $report->refresh();
        $this->assertSame('property_damage', $report->incident_type);
        $this->assertSame('serious', $report->severity);
        $this->assertCount(2, $report->incident_items);

        $audit = DB::connection('audit')->table('audit_log')
            ->where('table_name', 'incident_reports')
            ->where('record_id', $report->id)
            ->where('event_type', 'incident_report.updated')
            ->first();
        $this->assertNotNull($audit);
        $this->assertSame($lesseeId, $audit->user_id);
        $old = json_decode($audit->old_values, true);
        $new = json_decode($audit->new_values, true);
        $this->assertSame('trespassing', $old['incident_items'][0]['type']);
        $this->assertCount(2, $new['incident_items']);
    }

    public function test_add_admin_note_appends_a_timestamped_note_and_audits(): void
    {
        $lesseeId = (string) Str::uuid();
        $lease    = $this->lease($lesseeId, (string) Str::uuid());
        $report   = $this->track($this->service()->file($lease, $this->user($lesseeId), $this->data()));

        $admin = (string) Str::uuid();
        $note  = $this->service()->addAdminNote($report->id, '  Called the sheriff; awaiting callback.  ', $admin);

        $this->assertSame($report->id, $note->incident_report_id);
        $this->assertSame($admin, $note->author_user_id);
        $this->assertSame('Called the sheriff; awaiting callback.', $note->note, 'the note body is trimmed');
        $this->assertNotNull($note->created_at, 'the note is timestamped');

        $audit = DB::connection('audit')->table('audit_log')
            ->where('table_name', 'incident_admin_notes')
            ->where('record_id', $note->id)
            ->where('event_type', 'incident_report.note_added')
            ->first();
        $this->assertNotNull($audit, 'adding an investigation note should be audited');
        $this->assertSame($admin, $audit->user_id);
    }

    public function test_add_admin_note_rejects_an_empty_note(): void
    {
        $lesseeId = (string) Str::uuid();
        $lease    = $this->lease($lesseeId, (string) Str::uuid());
        $report   = $this->track($this->service()->file($lease, $this->user($lesseeId), $this->data()));

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->addAdminNote($report->id, '   ', (string) Str::uuid());
    }

    public function test_admin_notes_returns_newest_first(): void
    {
        $lesseeId = (string) Str::uuid();
        $lease    = $this->lease($lesseeId, (string) Str::uuid());
        $report   = $this->track($this->service()->file($lease, $this->user($lesseeId), $this->data()));
        $admin    = (string) Str::uuid();

        $first  = $this->service()->addAdminNote($report->id, 'First note.', $admin);
        // Force a distinct, later timestamp so ordering is deterministic.
        DB::connection('incidents')->table('incident_admin_notes')
            ->where('id', $first->id)->update(['created_at' => now()->subMinute()]);
        $second = $this->service()->addAdminNote($report->id, 'Second note.', $admin);

        $notes = $this->service()->adminNotes($report->id);

        $this->assertSame([$second->id, $first->id], $notes->pluck('id')->all());
    }

    public function test_file_stores_involved_parties_with_a_minor_flag(): void
    {
        $lesseeId = (string) Str::uuid();
        $lease    = $this->lease($lesseeId, (string) Str::uuid());

        $report = $this->track($this->service()->file($lease, $this->user($lesseeId), $this->data([
            'parties' => [
                ['full_name' => '  Dale Gribble  ', 'is_minor' => false],
                ['full_name' => 'Bobby Hill', 'is_minor' => true],
                ['full_name' => '', 'is_minor' => true], // dropped — no name
            ],
        ])));

        $report->refresh();
        $this->assertCount(2, $report->parties_involved, 'nameless rows are dropped');
        $this->assertSame('Dale Gribble', $report->parties_involved[0]['full_name'], 'name is trimmed');
        $this->assertFalse($report->parties_involved[0]['is_minor']);
        $this->assertSame('Bobby Hill', $report->parties_involved[1]['full_name']);
        $this->assertTrue($report->parties_involved[1]['is_minor']);
    }

    public function test_update_details_replaces_parties_and_audits_a_count_only(): void
    {
        $lesseeId = (string) Str::uuid();
        $lease    = $this->lease($lesseeId, (string) Str::uuid());
        $report   = $this->track($this->service()->file($lease, $this->user($lesseeId), $this->data()));

        $actor = (string) Str::uuid();
        $this->service()->updateDetails($report->id, [
            'parties' => [
                ['full_name' => 'Hank Hill', 'is_minor' => false],
                ['full_name' => 'Bobby Hill', 'is_minor' => true],
            ],
        ], $actor);

        $report->refresh();
        $this->assertCount(2, $report->parties_involved);

        $audit = DB::connection('audit')->table('audit_log')
            ->where('table_name', 'incident_reports')
            ->where('record_id', $report->id)
            ->where('event_type', 'incident_report.parties_updated')
            ->first();
        $this->assertNotNull($audit, 'updating parties should be audited');
        $this->assertSame($actor, $audit->user_id);

        // The audit log records counts only — never the parties' names.
        $this->assertStringNotContainsString('Bobby Hill', (string) $audit->new_values);
        $new = json_decode($audit->new_values, true);
        $this->assertSame(2, $new['party_count']);
        $this->assertSame(1, $new['minor_count']);
    }

    public function test_update_details_skips_an_unchanged_parties_list(): void
    {
        $lesseeId = (string) Str::uuid();
        $lease    = $this->lease($lesseeId, (string) Str::uuid());
        $report   = $this->track($this->service()->file($lease, $this->user($lesseeId), $this->data([
            'parties' => [['full_name' => 'Hank Hill', 'is_minor' => false]],
        ])));

        $this->service()->updateDetails($report->id, [
            'parties' => [['full_name' => 'Hank Hill', 'is_minor' => false]],
        ], (string) Str::uuid());

        $count = DB::connection('audit')->table('audit_log')
            ->where('record_id', $report->id)
            ->where('event_type', 'incident_report.parties_updated')
            ->count();
        $this->assertSame(0, $count, 'an identical parties list is not re-audited');
    }

    public function test_for_lease_returns_reports_newest_occurrence_first(): void
    {
        $lesseeId = (string) Str::uuid();
        $lease    = $this->lease($lesseeId, (string) Str::uuid());

        $older = $this->track($this->service()->file($lease, $this->user($lesseeId), $this->data(['occurred_at' => now()->subDays(5)])));
        $newer = $this->track($this->service()->file($lease, $this->user($lesseeId), $this->data(['occurred_at' => now()->subDay()])));

        $rows = $this->service()->forLease($lease->id);

        $this->assertSame([$newer->id, $older->id], $rows->pluck('id')->all());
    }
}
