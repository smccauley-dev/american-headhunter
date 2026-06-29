<?php

namespace App\Services\Lease;

use App\Exceptions\OutOfStateHuntException;
use App\Models\Documents\Document;
use App\Models\Identity\HunterCredentials;
use App\Models\Identity\User;
use App\Models\Identity\UserProfile;
use App\Models\Lease\Lease;
use App\Models\Lease\LeaseApplication;
use App\Models\Lease\LeaseApplicationHunter;
use App\Models\Lease\LeaseApplicationReviewHistory;
use App\Models\Lease\LeaseHunter;
use App\Models\Property\Property;
use App\Services\Audit\AuditService;
use App\Services\BaseService;
use App\Services\Documents\DocumentService;
use App\Services\Platform\EntitlementService;
use App\Services\Platform\LegalService;
use App\Services\Property\PropertyService;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ApplicationService extends BaseService
{
    public function __construct(
        private readonly AuditService      $auditService,
        private readonly PropertyService   $propertyService,
        private readonly DocumentService   $documentService,
        private readonly LegalService      $legalService,
        private readonly LeaseService      $leaseService,
        private readonly EsignatureService $esignatureService,
        private readonly EntitlementService $entitlementService,
    ) {}

    // ── Read ──────────────────────────────────────────────────────────────────

    public function getPendingForListing(string $listingId): Collection
    {
        return LeaseApplication::scopePending()
            ->scopeForListing($listingId)
            ->orderBy('created_at')
            ->get();
    }

    public function findOrFail(string $applicationId): LeaseApplication
    {
        return LeaseApplication::findOrFail($applicationId);
    }

    public function getHuntersForApplication(string $applicationId): Collection
    {
        return LeaseApplicationHunter::where('application_id', $applicationId)
            ->orderByRaw("hunter_type = 'primary' DESC")
            ->orderBy('created_at')
            ->get();
    }

    // ── Writes ────────────────────────────────────────────────────────────────

    /**
     * @param  array  $attributes  Application-level fields
     * @param  array  $hunters     Array of hunter snapshot arrays
     */
    public function submit(array $attributes, array $hunters = []): LeaseApplication
    {
        $this->assertApplicantMayHuntListing(
            $attributes['applicant_user_id'] ?? null,
            $attributes['listing_id'] ?? null,
        );

        $attributes['desired_hunters'] = count($hunters) ?: ($attributes['desired_hunters'] ?? 1);

        $application = LeaseApplication::create(array_merge(
            $attributes,
            $this->buildListingSnapshot($attributes['listing_id'] ?? null),
            ['status' => 'pending'],
        ));

        foreach ($hunters as $hunter) {
            $dob     = isset($hunter['date_of_birth']) && $hunter['date_of_birth']
                ? Carbon::parse($hunter['date_of_birth'])
                : null;
            $isMinor = $dob !== null && $dob->age < 18;

            LeaseApplicationHunter::create(array_merge(
                $this->filterHunterFields($hunter),
                [
                    'application_id' => $application->id,
                    'is_minor'       => $isMinor,
                ]
            ));
        }

        $this->auditService->log(
            eventType:      'lease_application.submitted',
            sourceDatabase: 'ah_lease',
            tableName:      'lease_applications',
            recordId:       $application->id,
            userId:         $application->applicant_user_id,
            actionSummary:  'Lease application submitted',
            newValues:      ['hunter_count' => count($hunters)],
        );

        return $application;
    }

    /**
     * Atomic submit: wraps credentials sync, application creation, and legal acceptance
     * in a single lease-connection transaction. Documents are created as 'unattached'
     * by buildHuntersPayload() before this call; on success they are promoted to
     * 'processing'. On failure the lease savepoint rolls back and documents are
     * soft-deleted as immediate compensation (the reaper covers process-death cases).
     */
    public function submitAtomically(
        string $userId,
        array $attributes,
        array $hunters,
        ?object $certDoc,
        Request $request,
    ): LeaseApplication {
        $docIds = $this->collectDocumentIds($hunters);

        try {
            $application = DB::connection('lease')->transaction(
                function () use ($userId, $attributes, $hunters, $certDoc, $request): LeaseApplication {
                    $this->updatePrimaryHunterCredentials($userId, $hunters[0] ?? []);
                    $application = $this->submit($attributes, $hunters);

                    if ($certDoc) {
                        $this->legalService->recordAcceptance(
                            userId:          $userId,
                            documentKey:     $certDoc->document_key,
                            documentVersion: $certDoc->version,
                            request:         $request,
                            contextType:     'lease_application',
                            contextId:       $application->id,
                        );
                    }

                    return $application;
                }
            );
        } catch (\Throwable $e) {
            // Fast-path compensation — reaper handles process-death cases
            try { $this->documentService->deleteUnattachedByIds($docIds); } catch (\Throwable) {}
            throw $e;
        }

        // Lease committed — promote unattached documents to processing
        try { $this->documentService->attachDocuments($docIds); } catch (\Throwable) {}

        return $application;
    }

    /**
     * Vet-first approval. The landowner approves the applicant BEFORE any money
     * changes hands; this opens a booking-fee window (default 24h) in which the
     * applicant must pay the held booking fee to claim the spot. No lease,
     * reservation, or signing request is created here — those are deferred to the
     * booking-fee win (onBookingFeePaid). The listing stays on-market so other
     * applicants can also be approved and compete to pay first.
     */
    public function approve(string $applicationId, string $reviewerUserId, int $bookingWindowHours = 24): LeaseApplication
    {
        $application  = LeaseApplication::findOrFail($applicationId);
        $fromStatus   = $application->status;

        if (! in_array($fromStatus, ['pending', 'under_review'], true)) {
            throw new \RuntimeException('Only a pending application can be approved.');
        }

        $application->update([
            'status'               => 'approved',
            'reviewed_by_user_id'  => $reviewerUserId,
            'reviewed_at'          => now(),
            'rejection_reason'     => null,
            'booking_fee_deadline' => now()->addHours($bookingWindowHours),
            'closed_reason'        => null,
        ]);

        LeaseApplicationReviewHistory::create([
            'application_id'     => $applicationId,
            'decided_by_user_id' => $reviewerUserId,
            'from_status'        => $fromStatus !== 'pending' ? $fromStatus : null,
            'to_status'          => 'approved',
        ]);

        $this->auditService->log(
            eventType:      'lease_application.approved',
            sourceDatabase: 'ah_lease',
            tableName:      'lease_applications',
            recordId:       $applicationId,
            userId:         $reviewerUserId,
            actionSummary:  "Lease application approved — applicant has {$bookingWindowHours}h to pay the booking fee",
        );

        return $application->refresh();
    }

    /**
     * Win path: the applicant has paid the held booking fee. Create the lease from
     * the listing's terms, reserve the term (the EXCLUDE constraint is the race-proof
     * guard — a second payer racing for the same listing loses here), create the
     * signing request, start the 7-day completion clock, and close the sibling
     * applications. A first-to-pay race must offer uniform terms, so the lease price
     * and dates come from the listing, not per-applicant negotiation.
     *
     * @return array{outcome: 'won'|'lost', lease: ?Lease} 'lost' means another
     *         applicant already reserved the listing — the caller refunds the fee.
     */
    public function onBookingFeePaid(string $applicationId, ?string $actorUserId = null): array
    {
        $application = LeaseApplication::find($applicationId);
        if (! $application) {
            throw new \RuntimeException("Booking fee paid for unknown application {$applicationId}.");
        }

        // Idempotency: a prior attempt already created the winning lease.
        $existingLease = Lease::where('application_id', $applicationId)
            ->whereNull('deleted_at')
            ->latest('created_at')
            ->first();
        if ($existingLease) {
            return ['outcome' => 'won', 'lease' => $existingLease];
        }

        // The spot is only claimable from an approved application. If it was closed
        // (deadline lapsed, or another applicant won), this payer lost the race.
        if ($application->status !== 'approved') {
            $this->markLost($application, $actorUserId);

            return ['outcome' => 'lost', 'lease' => null];
        }

        // property_id_snapshot may be null for older applications — fall back via listing
        $propertyId = $application->property_id_snapshot
            ?? DB::connection('property')
                ->table('property_listings')
                ->where('id', $application->listing_id)
                ->value('property_id');

        $property = $propertyId ? Property::on('property')->find($propertyId) : null;
        if (! $property) {
            throw new \RuntimeException('Property record not found for booking-fee win.');
        }

        $listing = $this->propertyService->findListing($application->listing_id);
        if (! $listing) {
            throw new \RuntimeException('Listing not found for booking-fee win.');
        }

        // Uniform terms from the listing (a race can't have per-applicant pricing).
        $startDate  = $application->listing_season_start_snap ?? $listing->season_start;
        $endDate    = $application->listing_season_end_snap   ?? $listing->season_end;
        $totalPrice = (float) ($listing->price_total ?? 0);

        $lessorUser    = User::on('identity')->find($property->owner_user_id);
        $lesseeUser    = User::on('identity')->find($application->applicant_user_id);
        $lessorProfile = UserProfile::on('identity')->where('user_id', $property->owner_user_id)->first();
        $lesseeProfile = UserProfile::on('identity')->where('user_id', $application->applicant_user_id)->first();

        $lessorName = $lessorProfile
            ? trim("{$lessorProfile->first_name} {$lessorProfile->last_name}") ?: 'Landowner'
            : 'Landowner';
        $lesseeName = $lesseeProfile
            ? trim("{$lesseeProfile->first_name} {$lesseeProfile->last_name}") ?: 'Hunter'
            : 'Hunter';

        // The MLA the landowner attached to the listing, if any (else in-platform contract).
        $customPdf = null;
        $listingContractDocId = DB::connection('property')
            ->table('property_listings')
            ->where('id', $application->listing_id)
            ->value('custom_contract_document_id');
        if ($listingContractDocId) {
            $customPdf = Document::on('documents')->find($listingContractDocId);
        }

        // Lease + hunter commit together. The 7-day completion clock starts now.
        $lease = DB::connection('lease')->transaction(function () use ($application, $property, $startDate, $endDate, $totalPrice, $actorUserId): Lease {
            $lease = $this->leaseService->createFromApplication($application->id, [
                'property_id'         => $property->id,
                'listing_id'          => $application->listing_id,
                'lessee_user_id'      => $application->applicant_user_id,
                'lessor_user_id'      => $property->owner_user_id,
                'start_date'          => $startDate,
                'end_date'            => $endDate,
                'total_price'         => $totalPrice,
                'deposit_paid'        => 0.00,
                'completion_deadline' => now()->addDays(7),
            ], $actorUserId);

            LeaseHunter::create([
                'lease_id'    => $lease->id,
                'user_id'     => $application->applicant_user_id,
                'role'        => 'primary',
                'is_approved' => false,
            ]);

            return $lease;
        });

        // Reserve the term — the EXCLUDE constraint is the race guard. A conflict
        // means a concurrent payer already won; compensate and report a loss so the
        // caller refunds this fee. (No-op for day-hunt listings, which aren't the
        // vet-first booking-fee use case — they reserve at activation.)
        try {
            $this->propertyService->reserveExclusiveLease(
                listingId:       $application->listing_id,
                start:           Carbon::parse($startDate),
                end:             Carbon::parse($endDate),
                hunters:         $lease->hunters()->count(),
                cost:            $totalPrice,
                leaseId:         $lease->id,
                createdByUserId: $actorUserId,
            );
        } catch (\RuntimeException $e) {
            $this->discardLostLease($lease);
            $this->markLost($application, $actorUserId);

            return ['outcome' => 'lost', 'lease' => null];
        }

        // Signing request lives in the documents DB — compensate the lease DB and
        // release the reservation if it fails, then rethrow so the booking-fee
        // webhook retries (the held fee stays put meanwhile).
        try {
            $this->esignatureService->createRequest(
                $lease,
                $actorUserId,
                ['user_id' => $property->owner_user_id, 'name' => $lessorName, 'email' => $lessorUser?->email ?? ''],
                ['user_id' => $application->applicant_user_id, 'name' => $lesseeName, 'email' => $lesseeUser?->email ?? ''],
                $customPdf,
            );
        } catch (\Throwable $e) {
            rescue(fn () => $this->propertyService->releaseBooking($lease->id));
            $this->discardLostLease($lease);
            throw $e;
        }

        // The listing is off-market now — close the other live applications for it.
        $this->closeSiblingApplications($application->listing_id, $application->id, $actorUserId);

        $this->auditService->log(
            eventType:      'lease_application.booking_fee_won',
            sourceDatabase: 'ah_lease',
            tableName:      'lease_applications',
            recordId:       $application->id,
            userId:         $actorUserId,
            actionSummary:  'Booking fee paid — lease created and spot claimed (7-day completion window opened)',
        );

        return ['outcome' => 'won', 'lease' => $lease];
    }

    /**
     * Close an approved application whose 24-hour booking-fee window lapsed without
     * payment. Driven by the deadline-enforcement command. No-op unless still
     * 'approved' (the applicant may have paid in the meantime).
     */
    public function closeForUnpaidBookingFee(string $applicationId, ?string $actorUserId = null): void
    {
        $application = LeaseApplication::find($applicationId);
        if (! $application || $application->status !== 'approved') {
            return;
        }

        $application->update([
            'status'        => 'closed',
            'closed_reason' => 'Booking Fee was not paid',
        ]);

        $this->auditService->log(
            eventType:      'lease_application.closed',
            sourceDatabase: 'ah_lease',
            tableName:      'lease_applications',
            recordId:       $applicationId,
            userId:         $actorUserId,
            actionSummary:  'Application closed — booking fee not paid within the window',
        );
    }

    /**
     * Close the still-live applications for a listing once one applicant has won it.
     * Builder update (not per-model) so a stale in-memory instance can't dirty-check
     * the write to a no-op; one summary audit covers the batch.
     */
    private function closeSiblingApplications(string $listingId, string $winnerApplicationId, ?string $actorUserId): void
    {
        $siblingIds = LeaseApplication::where('listing_id', $listingId)
            ->where('id', '!=', $winnerApplicationId)
            ->whereIn('status', ['pending', 'under_review', 'approved'])
            ->whereNull('deleted_at')
            ->pluck('id');

        if ($siblingIds->isEmpty()) {
            return;
        }

        LeaseApplication::whereIn('id', $siblingIds)->update([
            'status'        => 'closed',
            'closed_reason' => 'Another applicant booked this listing first',
        ]);

        $this->auditService->log(
            eventType:      'lease_application.closed',
            sourceDatabase: 'ah_lease',
            tableName:      'lease_applications',
            recordId:       $winnerApplicationId,
            userId:         $actorUserId,
            actionSummary:  "Closed {$siblingIds->count()} sibling application(s) — listing booked by the winning applicant",
        );
    }

    /** Mark a losing payer's application closed (race lost). Idempotent. */
    private function markLost(LeaseApplication $application, ?string $actorUserId): void
    {
        if (in_array($application->status, ['closed', 'cancelled', 'withdrawn', 'rejected'], true)) {
            return;
        }

        LeaseApplication::where('id', $application->id)->update([
            'status'        => 'closed',
            'closed_reason' => 'Another applicant booked this listing first',
        ]);

        $this->auditService->log(
            eventType:      'lease_application.closed',
            sourceDatabase: 'ah_lease',
            tableName:      'lease_applications',
            recordId:       $application->id,
            userId:         $actorUserId,
            actionSummary:  'Application closed — lost the booking-fee race; fee refunded',
        );
    }

    /**
     * Hard-delete a lease (and its hunters) created for a booking-fee attempt that
     * lost the reservation race. A soft delete would linger and uq_leases_application_id
     * counts soft-deleted rows, permanently blocking a later legitimate lease for the
     * same application.
     */
    private function discardLostLease(Lease $lease): void
    {
        rescue(function () use ($lease) {
            DB::connection('lease')->transaction(function () use ($lease): void {
                LeaseHunter::where('lease_id', $lease->id)->forceDelete();
                $lease->forceDelete();
            });
        });
    }

    public function reject(string $applicationId, string $reviewerUserId, string $reason): LeaseApplication
    {
        $application = LeaseApplication::findOrFail($applicationId);
        $fromStatus  = $application->status;

        $application->update([
            'status'              => 'rejected',
            'reviewed_by_user_id' => $reviewerUserId,
            'reviewed_at'         => now(),
            'rejection_reason'    => $reason,
        ]);

        LeaseApplicationReviewHistory::create([
            'application_id'     => $applicationId,
            'decided_by_user_id' => $reviewerUserId,
            'from_status'        => $fromStatus !== 'pending' ? $fromStatus : null,
            'to_status'          => 'rejected',
            'reason'             => $reason,
        ]);

        $this->auditService->log(
            eventType:      'lease_application.rejected',
            sourceDatabase: 'ah_lease',
            tableName:      'lease_applications',
            recordId:       $applicationId,
            userId:         $reviewerUserId,
            actionSummary:  'Lease application rejected',
            newValues:      ['rejection_reason' => $reason],
        );

        return $application->refresh();
    }

    public function override(
        string $applicationId,
        string $reviewerUserId,
        string $newStatus,
        string $reason
    ): LeaseApplication {
        if (! in_array($newStatus, ['approved', 'rejected'], true)) {
            throw new \InvalidArgumentException("Invalid override status '{$newStatus}'. Must be 'approved' or 'rejected'.");
        }

        $application = LeaseApplication::findOrFail($applicationId);
        $fromStatus  = $application->status;

        // Overriding an approval must not orphan a live lease: cancel the
        // lease and signing request while unsigned, refuse once signed.
        if ($newStatus === 'rejected' && $fromStatus === 'approved') {
            $lease = Lease::where('application_id', $applicationId)
                ->whereNull('deleted_at')
                ->latest('created_at')
                ->first();

            if ($lease) {
                if ($lease->status === 'active' || $this->esignatureService->hasAnySignature($lease->id)) {
                    throw new \RuntimeException(
                        'Cannot override to rejected: signatures have already been recorded on the lease. '
                        . 'Terminate the lease first, then override the application decision.'
                    );
                }

                $this->esignatureService->cancelRequest($lease->id, $reviewerUserId);

                // LeaseService::cancel() writes the canonical 'lease.cancelled'
                // audit event (with the reviewer as actor).
                $this->leaseService->cancel(
                    $lease->id,
                    "Application approval overridden to rejected: {$reason}",
                    $reviewerUserId,
                );
            }
        }

        $application->update([
            'status'               => $newStatus,
            'reviewed_by_user_id'  => $reviewerUserId,
            'reviewed_at'          => now(),
            'rejection_reason'     => $newStatus === 'rejected' ? $reason : null,
            // Overriding to approved opens the same 24h booking-fee window as a
            // normal approval; clear it (and any closed_reason) otherwise.
            'booking_fee_deadline' => $newStatus === 'approved' ? now()->addHours(24) : null,
            'closed_reason'        => null,
        ]);

        LeaseApplicationReviewHistory::create([
            'application_id'     => $applicationId,
            'decided_by_user_id' => $reviewerUserId,
            'from_status'        => $fromStatus,
            'to_status'          => $newStatus,
            'reason'             => $reason,
        ]);

        $this->auditService->log(
            eventType:      'lease_application.overridden',
            sourceDatabase: 'ah_lease',
            tableName:      'lease_applications',
            recordId:       $applicationId,
            userId:         $reviewerUserId,
            actionSummary:  "Decision overridden: {$fromStatus} → {$newStatus}",
            newValues:      ['reason' => $reason, 'new_status' => $newStatus],
        );

        return $application->refresh();
    }

    public function withdraw(string $applicationId): void
    {
        $application = LeaseApplication::findOrFail($applicationId);
        $application->update(['status' => 'withdrawn']);

        $this->auditService->log(
            eventType:      'lease_application.withdrawn',
            sourceDatabase: 'ah_lease',
            tableName:      'lease_applications',
            recordId:       $applicationId,
            actionSummary:  'Lease application withdrawn by applicant',
        );
    }

    /**
     * Resolve uploaded files into document IDs and build the normalized hunter payload.
     * Each hunter's uploads are wrapped in a documents-connection transaction so that a
     * partial failure across multiple uploads for the same hunter doesn't orphan earlier ones.
     * Documents are created in 'unattached' status — the caller must promote them via
     * attachDocuments() once its own transaction commits.
     *
     * @param  array  $huntersInput   Validated hunter data from the request
     * @param  array  $uploadedFiles  Keyed by [$index][$field] => UploadedFile|null
     * @param  string $userId         Owner for stored documents
     */
    public function buildHuntersPayload(array $huntersInput, array $uploadedFiles, string $userId): array
    {
        $result = [];

        foreach ($huntersInput as $i => $hunterData) {
            $docIds = [
                'dl'       => $hunterData['dl_document_id'] ?? null,
                'dl_back'  => $hunterData['dl_document_id_back'] ?? null,
                'lic'      => $hunterData['hunting_license_document_id'] ?? null,
                'lic_back' => $hunterData['hunting_license_document_id_back'] ?? null,
            ];

            $uploads = $uploadedFiles[$i] ?? [];

            if (! empty($uploads)) {
                $docIds = DB::connection('documents')->transaction(
                    function () use ($uploads, $userId, $docIds): array {
                        if (! empty($uploads['dl_photo'])) {
                            $docIds['dl'] = $this->documentService
                                ->storeUploadedFile($uploads['dl_photo'], $userId, 'driver_license', unattached: true)
                                ->id;
                        }
                        if (! empty($uploads['dl_photo_back'])) {
                            $docIds['dl_back'] = $this->documentService
                                ->storeUploadedFile($uploads['dl_photo_back'], $userId, 'driver_license', unattached: true)
                                ->id;
                        }
                        if (! empty($uploads['hunting_license_photo'])) {
                            $docIds['lic'] = $this->documentService
                                ->storeUploadedFile($uploads['hunting_license_photo'], $userId, 'hunting_license', unattached: true)
                                ->id;
                        }
                        if (! empty($uploads['hunting_license_photo_back'])) {
                            $docIds['lic_back'] = $this->documentService
                                ->storeUploadedFile($uploads['hunting_license_photo_back'], $userId, 'hunting_license', unattached: true)
                                ->id;
                        }
                        return $docIds;
                    }
                );
            }

            $result[] = [
                'hunter_type'                       => $hunterData['hunter_type'] ?? 'guest',
                'user_id'                           => $hunterData['user_id'] ?? null,
                'guest_hunter_id'                   => $hunterData['guest_hunter_id'] ?? null,
                'first_name'                        => $hunterData['first_name'],
                'last_name'                         => $hunterData['last_name'],
                'date_of_birth'                     => $hunterData['date_of_birth'] ?? null,
                'email'                             => $hunterData['email'] ?? null,
                'home_phone'                        => $hunterData['home_phone'] ?? null,
                'cell_phone'                        => $hunterData['cell_phone'] ?? null,
                'address_line1'                     => $hunterData['address_line1'] ?? null,
                'address_line2'                     => $hunterData['address_line2'] ?? null,
                'city'                              => $hunterData['city'] ?? null,
                'state_code'                        => $hunterData['state_code'] ?? null,
                'zip_code'                          => $hunterData['zip_code'] ?? null,
                'emergency_contact_name'            => $hunterData['emergency_contact_name'] ?? null,
                'emergency_contact_phone'           => $hunterData['emergency_contact_phone'] ?? null,
                'emergency_contact_relationship'    => $hunterData['emergency_contact_relationship'] ?? null,
                'medical_conditions'                => $hunterData['medical_conditions'] ?? null,
                'dl_number'                         => $hunterData['dl_number'] ?? null,
                'dl_state'                          => $hunterData['dl_state'] ?? null,
                'dl_expiry'                         => $hunterData['dl_expiry'] ?? null,
                'dl_document_id'                    => $docIds['dl'],
                'dl_document_id_back'               => $docIds['dl_back'],
                'dl_confirmed_current'              => filter_var($hunterData['dl_confirmed_current'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'hunting_license_number'            => $hunterData['hunting_license_number'] ?? null,
                'hunting_license_state'             => $hunterData['hunting_license_state'] ?? null,
                'hunting_license_expiry'            => $hunterData['hunting_license_expiry'] ?? null,
                'hunting_license_document_id'       => $docIds['lic'],
                'hunting_license_document_id_back'  => $docIds['lic_back'],
                'hunting_license_confirmed_current' => filter_var($hunterData['hunting_license_confirmed_current'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ];
        }

        return $result;
    }

    /**
     * Sync a primary hunter's submitted data back to their HunterCredentials profile row.
     * Only overwrites document IDs when a new upload occurred (non-empty value).
     */
    public function updatePrimaryHunterCredentials(string $userId, array $hunter): void
    {
        if (empty($hunter)) {
            return;
        }

        $fields = [
            'address_line1'                  => $hunter['address_line1'] ?? null,
            'address_line2'                  => $hunter['address_line2'] ?? null,
            'city'                           => $hunter['city'] ?? null,
            'state_code'                     => $hunter['state_code'] ?? null,
            'zip_code'                       => $hunter['zip_code'] ?? null,
            'home_phone'                     => $hunter['home_phone'] ?? null,
            'cell_phone'                     => $hunter['cell_phone'] ?? null,
            'emergency_contact_name'         => $hunter['emergency_contact_name'] ?? null,
            'emergency_contact_phone'        => $hunter['emergency_contact_phone'] ?? null,
            'emergency_contact_relationship' => $hunter['emergency_contact_relationship'] ?? null,
            'medical_conditions'             => $hunter['medical_conditions'] ?? null,
            'dl_number'                      => $hunter['dl_number'] ?? null,
            'dl_state'                       => $hunter['dl_state'] ?? null,
            'dl_expiry'                      => $hunter['dl_expiry'] ?? null,
            'hunting_license_number'         => $hunter['hunting_license_number'] ?? null,
            'hunting_license_state'          => $hunter['hunting_license_state'] ?? null,
            'hunting_license_expiry'         => $hunter['hunting_license_expiry'] ?? null,
        ];

        if (! empty($hunter['dl_document_id'])) {
            $fields['dl_document_id'] = $hunter['dl_document_id'];
        }
        if (! empty($hunter['dl_document_id_back'])) {
            $fields['dl_document_id_back'] = $hunter['dl_document_id_back'];
        }
        if (! empty($hunter['hunting_license_document_id'])) {
            $fields['hunting_license_document_id'] = $hunter['hunting_license_document_id'];
        }
        if (! empty($hunter['hunting_license_document_id_back'])) {
            $fields['hunting_license_document_id_back'] = $hunter['hunting_license_document_id_back'];
        }

        HunterCredentials::on('identity')->updateOrCreate(
            ['user_id' => $userId],
            $fields,
        );
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function collectDocumentIds(array $hunters): array
    {
        $ids = [];
        foreach ($hunters as $h) {
            foreach (['dl_document_id', 'dl_document_id_back', 'hunting_license_document_id', 'hunting_license_document_id_back'] as $field) {
                if (! empty($h[$field])) {
                    $ids[] = $h[$field];
                }
            }
        }
        return $ids;
    }

    /**
     * Authoritative single-state gate. If the applicant's membership locks them
     * to one state (single_state_hunt, and not overridden by multi_state_hunt),
     * a listing outside that state is rejected. This is the backstop behind the
     * UI's disabled Apply button — every application funnels through submit(), so
     * the gate cannot be bypassed by hitting the endpoint directly. Listings with
     * no resolvable state, or hunters with no recorded original state, are allowed
     * (cannot restrict against an unknown).
     */
    private function assertApplicantMayHuntListing(?string $applicantUserId, ?string $listingId): void
    {
        if (! $applicantUserId || ! $listingId) {
            return;
        }

        $state = $this->propertyService->findListing($listingId)?->property?->state_code;
        if (! $state) {
            return;
        }

        $user = User::on('identity')->find($applicantUserId);
        if (! $user || $this->entitlementService->canHuntInState($user, $state)) {
            return;
        }

        throw new OutOfStateHuntException($state, $this->entitlementService->restrictedHuntState($user));
    }

    private function buildListingSnapshot(?string $listingId): array
    {
        if (! $listingId) {
            return [];
        }

        $listing  = $this->propertyService->findListing($listingId);
        $property = $listing?->property;

        if (! $listing || ! $property) {
            return [];
        }

        return [
            'property_id_snapshot'       => $property->id,
            'property_title_snapshot'    => $property->title,
            'property_slug_snapshot'     => $property->slug,
            'property_location_snapshot' => trim("{$property->county} County, {$property->state_code}"),
            'listing_season_start_snap'  => $listing->season_start,
            'listing_season_end_snap'    => $listing->season_end,
        ];
    }

    private function filterHunterFields(array $hunter): array
    {
        $allowed = [
            'hunter_type', 'user_id', 'guest_hunter_id',
            'first_name', 'last_name', 'date_of_birth',
            'email', 'home_phone', 'cell_phone',
            'address_line1', 'address_line2', 'city', 'state_code', 'zip_code',
            'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relationship',
            'medical_conditions',
            'dl_number', 'dl_state', 'dl_expiry', 'dl_document_id', 'dl_document_id_back', 'dl_confirmed_current',
            'hunting_license_number', 'hunting_license_state', 'hunting_license_expiry',
            'hunting_license_document_id', 'hunting_license_document_id_back', 'hunting_license_confirmed_current',
        ];

        return array_intersect_key($hunter, array_flip($allowed));
    }
}
