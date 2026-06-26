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
                'incident_type'        => 'wildlife_encounter',
                'severity'             => 'serious',
                'occurred_at'          => now()->subHours(3)->format('Y-m-d\TH:i'),
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

    public function test_landowner_party_may_also_report(): void
    {
        $this->withSession(['auth.user_id' => $this->landownerId])
            ->post("/member/leases/{$this->leaseId}/incidents", [
                'incident_type' => 'trespassing',
                'severity'      => 'moderate',
                'occurred_at'   => now()->subDay()->format('Y-m-d\TH:i'),
                'description'   => 'Found a deer stand that is not ours on the property.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame($this->landownerId, IncidentReport::where('lease_id', $this->leaseId)->value('reporter_user_id'));
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
                'incident_type' => 'other',
                'severity'      => 'minor',
                'occurred_at'   => now()->subDay()->format('Y-m-d\TH:i'),
                'description'   => 'Not my lease.',
            ])
            ->assertNotFound();

        $this->assertSame(0, IncidentReport::where('lease_id', $this->leaseId)->count());

        DB::connection('identity')->table('users')->where('id', $strangerId)->delete();
    }
}
