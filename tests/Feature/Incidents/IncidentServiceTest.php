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
