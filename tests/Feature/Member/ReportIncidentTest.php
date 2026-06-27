<?php

namespace Tests\Feature\Member;

use App\Models\Incidents\IncidentReport;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The member-portal incident-report route (db.system) authors an IncidentReport and
 * attaches uploaded photo evidence. Either party to the lease may file. Lease rows are
 * real (owner role in tests bypasses RLS); storage and the virus-scan queue are faked.
 */
class ReportIncidentTest extends TestCase
{
    private string $hunterId;
    private string $landownerId;
    private string $leaseId;
    private string $applicationId;
    private string $propertyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake(config('filesystems.defaults.documents', 'local'));
        Queue::fake();

        $this->hunterId      = (string) Str::uuid();
        $this->landownerId   = (string) Str::uuid();
        $this->leaseId       = (string) Str::uuid();
        $this->applicationId = (string) Str::uuid();
        $this->propertyId    = (string) Str::uuid();

        foreach ([[$this->hunterId, 'hunter'], [$this->landownerId, 'landowner']] as [$id, $type]) {
            DB::connection('identity')->table('users')->insert([
                'id'            => $id,
                'email'         => "incident-{$type}-{$id}@test.invalid",
                'password_hash' => 'test-hash',
                'status'        => 'active',
                'account_type'  => $type,
                'trust_score'   => 80,
            ]);
        }

        DB::connection('lease')->table('lease_applications')->insert([
            'id'                => $this->applicationId,
            'listing_id'        => (string) Str::uuid(),
            'applicant_user_id' => $this->hunterId,
            'application_type'  => 'individual',
            'status'            => 'approved',
        ]);

        DB::connection('lease')->table('leases')->insert([
            'id'             => $this->leaseId,
            'application_id' => $this->applicationId,
            'property_id'    => $this->propertyId,
            'listing_id'     => (string) Str::uuid(),
            'lessee_user_id' => $this->hunterId,
            'lessor_user_id' => $this->landownerId,
            'status'         => 'active',
            'start_date'     => '2026-10-01',
            'end_date'       => '2026-11-30',
            'total_price'    => '2500.00',
            'deposit_paid'   => '500.00',
        ]);
    }

    protected function tearDown(): void
    {
        DB::connection('incidents')->table('incident_reports')->where('lease_id', $this->leaseId)->delete();
        DB::connection('documents')->table('documents')->whereIn('owner_user_id', [$this->hunterId, $this->landownerId])->delete();
        DB::connection('lease')->table('leases')->where('id', $this->leaseId)->delete();
        DB::connection('lease')->table('lease_applications')->where('id', $this->applicationId)->delete();
        DB::connection('identity')->table('users')->whereIn('id', [$this->hunterId, $this->landownerId])->delete();

        parent::tearDown();
    }

    public function test_report_route_files_an_incident_with_evidence(): void
    {
        $this->withSession(['auth.user_id' => $this->hunterId])
            ->post("/member/leases/{$this->leaseId}/incidents", [
                'items'                => [
                    ['type' => 'wildlife_encounter', 'severity' => 'serious', 'occurred_at' => now()->subHours(3)->format('Y-m-d\TH:i')],
                ],
                'location_description' => 'Near the south food plot',
                'description'          => 'Aggressive bear encountered while walking to the stand.',
                'injuries_reported'    => true,
                'evidence'             => [UploadedFile::fake()->image('bear.jpg', 600, 400)],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $report = IncidentReport::where('lease_id', $this->leaseId)->first();
        $this->assertNotNull($report, 'the report route should author an incident row');
        $this->assertSame('open', $report->status);
        $this->assertSame('wildlife_encounter', $report->incident_type);
        $this->assertSame($this->hunterId, $report->reporter_user_id);
        $this->assertSame($this->propertyId, $report->property_id);
        $this->assertTrue($report->injuries_reported);
        $this->assertCount(1, (array) $report->evidence_document_ids, 'the photo evidence should be attached');
    }

    public function test_report_route_captures_involved_parties_with_a_minor_flag(): void
    {
        $this->withSession(['auth.user_id' => $this->hunterId])
            ->post("/member/leases/{$this->leaseId}/incidents", [
                'items'       => [
                    ['type' => 'hunting_accident', 'severity' => 'serious', 'occurred_at' => now()->subHours(2)->format('Y-m-d\TH:i')],
                ],
                'description' => 'A youth hunter slipped climbing into the stand.',
                'parties'     => [
                    ['full_name' => 'Hank Hill', 'is_minor' => false],
                    ['full_name' => 'Bobby Hill', 'is_minor' => true],
                    ['full_name' => '', 'is_minor' => false],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $report = IncidentReport::where('lease_id', $this->leaseId)->firstOrFail();
        // Nameless rows are dropped by the service; the minor flag is preserved.
        $this->assertCount(2, (array) $report->parties_involved);
        $this->assertSame('Bobby Hill', $report->parties_involved[1]['full_name']);
        $this->assertTrue($report->parties_involved[1]['is_minor']);
        $this->assertFalse($report->parties_involved[0]['is_minor']);
    }

    public function test_landowner_party_may_also_report(): void
    {
        $this->withSession(['auth.user_id' => $this->landownerId])
            ->post("/member/leases/{$this->leaseId}/incidents", [
                'items'       => [
                    ['type' => 'trespassing', 'severity' => 'moderate', 'occurred_at' => now()->subDay()->format('Y-m-d\TH:i')],
                ],
                'description' => 'Found a deer stand that is not ours on the property.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame($this->landownerId, IncidentReport::where('lease_id', $this->leaseId)->value('reporter_user_id'));
    }

    public function test_reporter_can_edit_their_incident_and_the_change_is_audited(): void
    {
        $this->withSession(['auth.user_id' => $this->hunterId])
            ->post("/member/leases/{$this->leaseId}/incidents", [
                'items'       => [
                    ['type' => 'trespassing', 'severity' => 'minor', 'occurred_at' => now()->subDay()->format('Y-m-d\TH:i')],
                ],
                'description' => 'Saw a vehicle I did not recognise.',
                'evidence'    => [UploadedFile::fake()->image('first.jpg', 400, 300)],
            ])->assertRedirect();

        $report  = IncidentReport::where('lease_id', $this->leaseId)->firstOrFail();
        $firstId = ((array) $report->evidence_document_ids)[0];
        $when    = $report->occurred_at->format('Y-m-d\TH:i');

        // The reporter corrects it to TWO line items — a property-damage and a medical issue.
        $this->withSession(['auth.user_id' => $this->hunterId])
            ->post("/member/leases/{$this->leaseId}/incidents/{$report->id}", [
                'items'       => [
                    ['type' => 'property_damage', 'severity' => 'serious', 'occurred_at' => $when],
                    ['type' => 'medical', 'severity' => 'moderate', 'occurred_at' => $when],
                ],
                'description' => 'Correction: the gate lock was cut and a hunter was injured.',
                'evidence'    => [UploadedFile::fake()->image('second.jpg', 400, 300)],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $report->refresh();
        $this->assertSame('property_damage', $report->incident_type);
        $this->assertSame('serious', $report->severity);
        $this->assertCount(2, (array) $report->incident_items);
        // Append-only evidence: the original photo is kept and the new one added.
        $evidence = (array) $report->evidence_document_ids;
        $this->assertCount(2, $evidence);
        $this->assertContains($firstId, $evidence);

        $audit = DB::connection('audit')->table('audit_log')
            ->where('table_name', 'incident_reports')
            ->where('record_id', $report->id)
            ->where('event_type', 'incident_report.updated')
            ->first();
        $this->assertNotNull($audit, 'a member edit must be audited');
        $this->assertSame($this->hunterId, $audit->user_id, 'the edit must be attributed to the reporter');
    }

    public function test_other_party_cannot_edit_someone_elses_incident(): void
    {
        $this->withSession(['auth.user_id' => $this->hunterId])
            ->post("/member/leases/{$this->leaseId}/incidents", [
                'items'       => [
                    ['type' => 'trespassing', 'severity' => 'minor', 'occurred_at' => now()->subDay()->format('Y-m-d\TH:i')],
                ],
                'description' => 'Hunter-filed incident.',
            ])->assertRedirect();

        $report = IncidentReport::where('lease_id', $this->leaseId)->firstOrFail();

        // The landowner is a lease party but not the reporter — the edit 404s.
        $this->withSession(['auth.user_id' => $this->landownerId])
            ->post("/member/leases/{$this->leaseId}/incidents/{$report->id}", [
                'items'       => [
                    ['type' => 'other', 'severity' => 'critical', 'occurred_at' => $report->occurred_at->format('Y-m-d\TH:i')],
                ],
                'description' => 'Trying to rewrite the hunter report.',
            ])
            ->assertNotFound();

        $this->assertSame('trespassing', $report->refresh()->incident_type);
    }

    public function test_a_resolved_incident_can_no_longer_be_edited(): void
    {
        $this->withSession(['auth.user_id' => $this->hunterId])
            ->post("/member/leases/{$this->leaseId}/incidents", [
                'items'       => [
                    ['type' => 'trespassing', 'severity' => 'minor', 'occurred_at' => now()->subDay()->format('Y-m-d\TH:i')],
                ],
                'description' => 'Will be resolved.',
            ])->assertRedirect();

        $report = IncidentReport::where('lease_id', $this->leaseId)->firstOrFail();
        DB::connection('incidents')->table('incident_reports')->where('id', $report->id)->update(['status' => 'resolved']);

        $this->withSession(['auth.user_id' => $this->hunterId])
            ->post("/member/leases/{$this->leaseId}/incidents/{$report->id}", [
                'items'       => [
                    ['type' => 'property_damage', 'severity' => 'serious', 'occurred_at' => $report->occurred_at->format('Y-m-d\TH:i')],
                ],
                'description' => 'Too late to edit.',
            ])
            ->assertForbidden();

        $this->assertSame('trespassing', $report->refresh()->incident_type);
    }

    public function test_report_route_rejects_a_non_party(): void
    {
        $strangerId = (string) Str::uuid();
        DB::connection('identity')->table('users')->insert([
            'id' => $strangerId, 'email' => "stranger-{$strangerId}@test.invalid",
            'password_hash' => 'test-hash', 'status' => 'active', 'account_type' => 'hunter',
        ]);

        $this->withSession(['auth.user_id' => $strangerId])
            ->post("/member/leases/{$this->leaseId}/incidents", [
                'items'       => [
                    ['type' => 'other', 'severity' => 'minor', 'occurred_at' => now()->subDay()->format('Y-m-d\TH:i')],
                ],
                'description' => 'Not my lease.',
            ])
            ->assertNotFound();

        $this->assertSame(0, IncidentReport::where('lease_id', $this->leaseId)->count());

        DB::connection('identity')->table('users')->where('id', $strangerId)->delete();
    }
}
