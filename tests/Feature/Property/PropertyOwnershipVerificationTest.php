<?php

namespace Tests\Feature\Property;

use App\Jobs\Property\SendOwnershipStatusEmail;
use App\Models\Property\PropertyOwnershipVerification;
use App\Services\Property\PropertyService;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Proof-of-ownership verification — the gate that stops a landowner listing a
 * property they can't show they own or manage. Pins the service lifecycle
 * (submit → supersede → approve / reject), the approved-ownership gate, and the
 * member-portal HTTP flow (staged upload → submit, and the activation block on
 * the property update route). Document ids reference DB 11 with no cross-DB FK,
 * so fake UUIDs are safe for the pure-service tests.
 */
class PropertyOwnershipVerificationTest extends TestCase
{
    private string $ownerId;
    private string $strangerId;
    private string $propertyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        $this->ownerId    = (string) Str::uuid();
        $this->strangerId = (string) Str::uuid();
        $this->propertyId = (string) Str::uuid();

        foreach ([[$this->ownerId, 'landowner'], [$this->strangerId, 'hunter']] as [$id, $type]) {
            DB::connection('identity')->table('users')->insert([
                'id'            => $id,
                'email'         => "ownproof-{$type}-{$id}@test.invalid",
                'password_hash' => 'test-hash',
                'status'        => 'active',
                'account_type'  => $type,
            ]);
        }

        DB::connection('property')->table('properties')->insert([
            'id'            => $this->propertyId,
            'owner_user_id' => $this->ownerId,
            'title'         => 'Proof Tract',
            'slug'          => "proof-tract-{$this->propertyId}",
            'status'        => 'draft',
            'state_code'    => 'TX',
            'county'        => 'Llano',
            'total_acres'   => '640.00',
        ]);
    }

    protected function tearDown(): void
    {
        DB::connection('property')->table('property_ownership_review_notes')->where('property_id', $this->propertyId)->delete();
        DB::connection('property')->table('property_ownership_verifications')->where('property_id', $this->propertyId)->delete();
        DB::connection('property')->table('properties')->where('id', $this->propertyId)->delete();
        DB::connection('documents')->table('documents')->where('owner_user_id', $this->ownerId)->delete();
        DB::connection('identity')->table('users')->whereIn('id', [$this->ownerId, $this->strangerId])->delete();

        // Reset the platform auto-approval flag so it can't leak into other tests.
        app(\App\Services\Platform\TenantService::class)->setSetting('properties.ownership_auto_approve', '0');

        foreach (['property', 'property_read', 'documents', 'identity', 'platform'] as $conn) {
            try { DB::connection($conn)->disconnect(); } catch (\Throwable) {}
        }

        parent::tearDown();
    }

    /** Set the platform-wide auto-approval flag for ownership proof. */
    private function setAutoApprove(bool $on): void
    {
        app(\App\Services\Platform\TenantService::class)
            ->setSetting('properties.ownership_auto_approve', $on ? '1' : '0');
    }

    private function service(): PropertyService
    {
        return app(PropertyService::class);
    }

    private function submit(?array $docIds = null, string $ownerType = 'individual'): PropertyOwnershipVerification
    {
        return $this->service()->submitOwnershipVerification(
            $this->propertyId,
            $this->ownerId,
            $ownerType,
            $ownerType === 'individual' ? null : 'North Forty Holdings, LLC',
            $docIds ?? [(string) Str::uuid()],
            'Jane Q. Landowner',
        );
    }

    public function test_submit_creates_a_submitted_verification_and_does_not_yet_approve(): void
    {
        $v = $this->submit();

        $this->assertSame('submitted', $v->status);
        $this->assertSame($this->ownerId, $v->submitted_by_user_id);
        $this->assertSame('Jane Q. Landowner', $v->certification_name);
        $this->assertNotNull($v->certified_at);
        $this->assertFalse($this->service()->hasApprovedOwnership($this->propertyId));
    }

    public function test_resubmitting_supersedes_the_prior_pending_submission(): void
    {
        $first  = $this->submit();
        $second = $this->submit();

        // Only the newest open row survives — the partial unique index needs it.
        $open = PropertyOwnershipVerification::on('property')
            ->where('property_id', $this->propertyId)
            ->whereIn('status', ['submitted', 'pending'])
            ->whereNull('deleted_at')
            ->get();

        $this->assertCount(1, $open);
        $this->assertSame($second->id, $open->first()->id);

        $old = PropertyOwnershipVerification::on('property')->withTrashed()->find($first->id);
        $this->assertNotNull($old->deleted_at);
    }

    public function test_approve_clears_the_property_to_go_live(): void
    {
        $v = $this->submit();

        $this->service()->approveOwnershipVerification($v->id, $this->ownerId);

        $this->assertTrue($this->service()->hasApprovedOwnership($this->propertyId));

        $detail = $this->service()->getOwnershipVerification($this->propertyId);
        $this->assertSame('approved', $detail['status']);
        $this->assertNotNull($detail['reviewed_at']);
    }

    public function test_reject_records_the_reason_and_keeps_ownership_unapproved(): void
    {
        $v = $this->submit();

        $this->service()->rejectOwnershipVerification($v->id, $this->ownerId, 'Deed name does not match.');

        $this->assertFalse($this->service()->hasApprovedOwnership($this->propertyId));

        $detail = $this->service()->getOwnershipVerification($this->propertyId);
        $this->assertSame('rejected', $detail['status']);
        $this->assertSame('Deed name does not match.', $detail['review_notes']);
    }

    public function test_property_cannot_go_active_until_ownership_is_approved(): void
    {
        $payload = [
            'title'       => 'Proof Tract',
            'status'      => 'active',
            'state_code'  => 'TX',
            'county'      => 'Llano',
            'total_acres' => '640',
        ];

        // No proof yet → blocked.
        $this->withSession(['auth.user_id' => $this->ownerId])
            ->put("/member/properties/{$this->propertyId}", $payload)
            ->assertRedirect()
            ->assertSessionHasErrors('status');

        $this->assertSame('draft', DB::connection('property')->table('properties')->where('id', $this->propertyId)->value('status'));

        // Approve → the same update now goes through.
        $v = $this->submit();
        $this->service()->approveOwnershipVerification($v->id, $this->ownerId);

        $this->withSession(['auth.user_id' => $this->ownerId])
            ->put("/member/properties/{$this->propertyId}", $payload)
            ->assertRedirect("/member/properties/{$this->propertyId}")
            ->assertSessionHasNoErrors();

        $this->assertSame('active', DB::connection('property')->table('properties')->where('id', $this->propertyId)->value('status'));
    }

    public function test_member_can_stage_and_submit_proof_documents(): void
    {
        Storage::fake('local');
        Storage::fake(config('filesystems.defaults.documents', 'local'));
        Queue::fake();

        // Stage one document through the FilePond temp endpoint.
        $token = $this->withSession(['auth.user_id' => $this->ownerId])
            ->post("/member/properties/{$this->propertyId}/ownership/temp", [
                'document' => UploadedFile::fake()->image('deed.jpg', 800, 600),
            ])
            ->assertOk()
            ->getContent();

        $this->assertNotEmpty($token);

        // Commit the staged token as the proof package.
        $this->withSession(['auth.user_id' => $this->ownerId])
            ->post("/member/properties/{$this->propertyId}/ownership", [
                'owner_type'             => 'individual',
                'tmp_files'              => [$token],
                'certification_name'     => 'Jane Q. Landowner',
                'certification_accepted' => true,
            ])
            ->assertRedirect("/member/properties/{$this->propertyId}")
            ->assertSessionHas('success');

        $v = PropertyOwnershipVerification::on('property')->where('property_id', $this->propertyId)->first();
        $this->assertNotNull($v);
        $this->assertSame('submitted', $v->status);
        $this->assertCount(1, (array) $v->document_ids);
    }

    public function test_submit_requires_the_perjury_certification(): void
    {
        $this->withSession(['auth.user_id' => $this->ownerId])
            ->post("/member/properties/{$this->propertyId}/ownership", [
                'owner_type'             => 'individual',
                'tmp_files'              => ['anything'],
                'certification_name'     => 'Jane Q. Landowner',
                'certification_accepted' => false,
            ])
            ->assertSessionHasErrors('certification_accepted');

        $this->assertSame(0, PropertyOwnershipVerification::on('property')->where('property_id', $this->propertyId)->count());
    }

    public function test_a_stranger_cannot_submit_proof_for_someone_elses_property(): void
    {
        $this->withSession(['auth.user_id' => $this->strangerId])
            ->post("/member/properties/{$this->propertyId}/ownership", [
                'owner_type'             => 'individual',
                'tmp_files'              => ['anything'],
                'certification_name'     => 'Bad Actor',
                'certification_accepted' => true,
            ])
            ->assertNotFound();

        $this->assertSame(0, PropertyOwnershipVerification::on('property')->where('property_id', $this->propertyId)->count());
    }

    public function test_marking_a_submission_under_review_sets_pending(): void
    {
        $v = $this->submit();
        $this->assertSame('submitted', $v->status);

        $this->service()->markOwnershipUnderReview($v->id, $this->ownerId);

        $detail = $this->service()->getOwnershipVerification($this->propertyId);
        $this->assertSame('pending', $detail['status']);

        // Still open / awaiting a decision — not yet approved.
        $this->assertFalse($this->service()->hasApprovedOwnership($this->propertyId));
    }

    public function test_auto_approval_immediately_approves_a_new_submission(): void
    {
        $this->setAutoApprove(true);

        $v = $this->submit();

        $this->assertSame('approved', $v->status);
        $this->assertTrue($this->service()->hasApprovedOwnership($this->propertyId));
        $this->assertNull($v->reviewed_by_user_id); // auto-approved — no human reviewer
    }

    public function test_enabling_auto_approval_sweeps_the_existing_backlog(): void
    {
        // The sweep is global; snapshot any unrelated open rows so we can restore the
        // dev DB after the test rather than clobbering someone else's draft data.
        $unrelated = PropertyOwnershipVerification::on('property')
            ->whereIn('status', ['submitted', 'pending'])
            ->whereNull('deleted_at')
            ->where('property_id', '!=', $this->propertyId)
            ->get(['id', 'status']);

        // A submission lands while auto-approval is OFF, so it waits for review.
        $v = $this->submit();
        $this->assertSame('submitted', $v->status);

        // Flipping the switch on clears the backlog in one sweep.
        $this->setAutoApprove(true);
        $swept = $this->service()->autoApproveOpenOwnershipVerifications($this->ownerId);

        $this->assertGreaterThanOrEqual(1, $swept);
        $this->assertTrue($this->service()->hasApprovedOwnership($this->propertyId));
        $this->assertNull(
            PropertyOwnershipVerification::on('property')->findOrFail($v->id)->reviewed_by_user_id
        );

        // Restore unrelated rows the global sweep approved.
        foreach ($unrelated as $row) {
            PropertyOwnershipVerification::on('property')->whereKey($row->id)->update([
                'status'              => $row->status,
                'reviewed_at'         => null,
                'reviewed_by_user_id' => null,
            ]);
        }
    }

    public function test_review_notes_are_recorded_with_author_and_kept_newest_first(): void
    {
        $v = $this->submit();

        $this->service()->addOwnershipReviewNote($this->propertyId, $v->id, $this->ownerId, 'Deed name unclear — following up.');
        $this->service()->addOwnershipReviewNote($this->propertyId, $v->id, $this->ownerId, 'Landowner sent a clearer scan.');

        $notes = $this->service()->getOwnershipReviewNotes($this->propertyId);

        $this->assertCount(2, $notes);
        $this->assertSame('Landowner sent a clearer scan.', $notes[0]['note']); // newest first
        $this->assertNotEmpty($notes[0]['author']);
        $this->assertNotEmpty($notes[0]['created_at']);
    }

    public function test_each_review_stage_queues_a_status_email_to_the_landowner(): void
    {
        Queue::fake();

        // Submitted (auto-approval off) → one "submitted" email to the submitter.
        $v = $this->submit();
        Queue::assertPushed(
            SendOwnershipStatusEmail::class,
            fn (SendOwnershipStatusEmail $job) => $job->status === 'submitted'
                && $job->submitterUserId === $this->ownerId
                && $job->propertyId === $this->propertyId
        );

        // Under review → "pending".
        $this->service()->markOwnershipUnderReview($v->id, $this->ownerId);
        Queue::assertPushed(
            SendOwnershipStatusEmail::class,
            fn (SendOwnershipStatusEmail $job) => $job->status === 'pending'
        );

        // Rejected → "rejected", carrying the reason for the email body.
        $this->service()->rejectOwnershipVerification($v->id, $this->ownerId, 'Deed name does not match.');
        Queue::assertPushed(
            SendOwnershipStatusEmail::class,
            fn (SendOwnershipStatusEmail $job) => $job->status === 'rejected'
                && $job->reviewNotes === 'Deed name does not match.'
        );

        // A fresh submission then approval → "approved".
        $v2 = $this->submit();
        $this->service()->approveOwnershipVerification($v2->id, $this->ownerId);
        Queue::assertPushed(
            SendOwnershipStatusEmail::class,
            fn (SendOwnershipStatusEmail $job) => $job->status === 'approved'
        );
    }

    public function test_auto_approval_sends_no_status_email(): void
    {
        Queue::fake();
        $this->setAutoApprove(true);

        $this->submit();

        // The auto path skips review entirely — nothing for the landowner to track.
        Queue::assertNotPushed(SendOwnershipStatusEmail::class);
    }

    public function test_service_rejects_a_new_submission_once_ownership_is_approved(): void
    {
        $v = $this->submit();
        $this->service()->approveOwnershipVerification($v->id, $this->ownerId);
        $this->assertTrue($this->service()->hasApprovedOwnership($this->propertyId));

        try {
            $this->submit();
            $this->fail('Expected a RuntimeException when submitting after approval.');
        } catch (\RuntimeException) {
            // expected — ownership verifies once and stays verified.
        }

        // The approved row is untouched: still exactly one open/active row, and it is
        // the original approved submission (nothing superseded, nothing added).
        $rows = PropertyOwnershipVerification::on('property')
            ->where('property_id', $this->propertyId)
            ->whereNull('deleted_at')
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame('approved', $rows->first()->status);
        $this->assertSame($v->id, $rows->first()->id);
    }

    public function test_member_route_rejects_a_new_submission_once_ownership_is_approved(): void
    {
        $v = $this->submit();
        $this->service()->approveOwnershipVerification($v->id, $this->ownerId);

        $this->withSession(['auth.user_id' => $this->ownerId])
            ->post("/member/properties/{$this->propertyId}/ownership", [
                'owner_type'             => 'individual',
                'tmp_files'              => ['anything'],
                'certification_name'     => 'Jane Q. Landowner',
                'certification_accepted' => true,
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        // No second submission was created.
        $count = PropertyOwnershipVerification::on('property')
            ->where('property_id', $this->propertyId)
            ->whereNull('deleted_at')
            ->count();

        $this->assertSame(1, $count);
    }
}
