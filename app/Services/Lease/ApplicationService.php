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
use Illuminate\Http\UploadedFile;
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

    public function approve(string $applicationId, string $reviewerUserId): LeaseApplication
    {
        $application  = LeaseApplication::findOrFail($applicationId);
        $fromStatus   = $application->status;

        $application->update([
            'status'              => 'approved',
            'reviewed_by_user_id' => $reviewerUserId,
            'reviewed_at'         => now(),
            'rejection_reason'    => null,
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
            actionSummary:  'Lease application approved',
        );

        return $application->refresh();
    }

    /**
     * Approve an application and create its lease, hunter record, and signing
     * request as one operation.
     *
     * The property and signer identities are resolved BEFORE any state change
     * so a missing property cannot strand the application in approved-without-
     * lease limbo. All lease-DB writes share one transaction; if the
     * documents-DB signing request fails afterwards, the lease-DB writes are
     * compensated (lease deleted, application reverted to pending).
     *
     * @param  array{start_date: mixed, end_date: mixed, total_price: mixed}  $leaseTerms
     * @param  ?UploadedFile  $customContractUpload  Admin-uploaded contract
     *         override; falls back to the listing's attached MLA when null.
     * @return array{lease: Lease, activated: bool, customPdfFailed: bool}
     */
    public function approveAndCreateLease(
        string        $applicationId,
        string        $reviewerUserId,
        array         $leaseTerms,
        ?UploadedFile $customContractUpload,
        bool          $signAsLessor,
        string        $ipAddress = '',
        string        $userAgent = '',
    ): array {
        $application = LeaseApplication::findOrFail($applicationId);

        if ($application->status !== 'pending') {
            throw new \RuntimeException('Only pending applications can be approved.');
        }

        // property_id_snapshot may be null for older applications — fall back via listing
        $propertyId = $application->property_id_snapshot
            ?? DB::connection('property')
                ->table('property_listings')
                ->where('id', $application->listing_id)
                ->value('property_id');

        $property = $propertyId ? Property::on('property')->find($propertyId) : null;

        if (! $property) {
            throw new \RuntimeException(
                'Property record not found — check the listing exists and has a valid property in the property database.'
            );
        }

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

        // Store the admin-uploaded contract override; a storage failure must
        // not block approval — fall through to the listing MLA / in-platform.
        $customPdf       = null;
        $customPdfFailed = false;
        if ($customContractUpload !== null) {
            try {
                $customPdf = $this->documentService->storeUploadedFile(
                    $customContractUpload,
                    $property->owner_user_id,
                    'contract',
                );
            } catch (\Throwable $e) {
                $customPdfFailed = true;
                Log::warning('ApplicationService: custom contract PDF store failed — falling back', [
                    'application_id' => $applicationId,
                    'error'          => $e->getMessage(),
                ]);
            }
        }

        // No admin override — fall back to the MLA the landowner attached to the listing
        if ($customPdf === null) {
            $listingContractDocId = DB::connection('property')
                ->table('property_listings')
                ->where('id', $application->listing_id)
                ->value('custom_contract_document_id');

            if ($listingContractDocId) {
                $customPdf = Document::on('documents')->find($listingContractDocId);
            }
        }

        // All lease-DB writes (approval, history, lease, hunter) commit together
        $lease = DB::connection('lease')->transaction(function () use ($application, $applicationId, $reviewerUserId, $property, $leaseTerms): Lease {
            $this->approve($applicationId, $reviewerUserId);

            $lease = $this->leaseService->createFromApplication($applicationId, [
                'property_id'    => $property->id,
                'listing_id'     => $application->listing_id,
                'lessee_user_id' => $application->applicant_user_id,
                'lessor_user_id' => $property->owner_user_id,
                'start_date'     => $leaseTerms['start_date'],
                'end_date'       => $leaseTerms['end_date'],
                'total_price'    => $leaseTerms['total_price'],
                'deposit_paid'   => 0.00,
            ], $reviewerUserId);

            LeaseHunter::create([
                'lease_id'    => $lease->id,
                'user_id'     => $application->applicant_user_id,
                'role'        => 'primary',
                'is_approved' => false,
            ]);

            return $lease;
        });

        // Exclusive (annual / seasonal) listings are committed at approval:
        // reserve the full term so a second overlapping approval is refused
        // (the EXCLUDE constraint is the race-proof guard) and pull the listing
        // from search. Day-hunt listings reserve at activation instead, so this
        // is a no-op for them. A conflict (already leased) compensates the
        // freshly created lease and surfaces a friendly error to the reviewer.
        try {
            $this->propertyService->reserveExclusiveLease(
                listingId:       $application->listing_id,
                start:           Carbon::parse($leaseTerms['start_date']),
                end:             Carbon::parse($leaseTerms['end_date']),
                hunters:         $lease->hunters()->count(),
                cost:            (float) $leaseTerms['total_price'],
                leaseId:         $lease->id,
                createdByUserId: $reviewerUserId,
            );
        } catch (\Throwable $e) {
            $this->compensateFailedApproval($application, $lease, $reviewerUserId);
            throw $e;
        }

        // Signing request lives in the documents DB — no shared transaction
        // is possible, so compensate the lease DB if this step fails.
        try {
            $esigRequest = $this->esignatureService->createRequest(
                $lease,
                $reviewerUserId,
                ['user_id' => $property->owner_user_id, 'name' => $lessorName, 'email' => $lessorUser?->email ?? ''],
                ['user_id' => $application->applicant_user_id, 'name' => $lesseeName, 'email' => $lesseeUser?->email ?? ''],
                $customPdf,
            );
        } catch (\Throwable $e) {
            $this->compensateFailedApproval($application, $lease, $reviewerUserId);
            throw $e;
        }

        // Lease and request both exist now — a signature failure here is
        // recoverable via the "Sign as Lessor" action, so don't compensate.
        $activated = false;
        if ($signAsLessor) {
            try {
                $activated = $this->esignatureService->recordSignature(
                    $esigRequest->id,
                    $property->owner_user_id,
                    $ipAddress,
                    $userAgent,
                    recordedByUserId: $reviewerUserId,
                );
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return ['lease' => $lease, 'activated' => $activated, 'customPdfFailed' => $customPdfFailed];
    }

    /**
     * Undo the lease-DB side of a failed approval: remove the lease and its
     * hunter rows and put the application back to pending so the admin can
     * retry once the underlying failure is fixed.
     */
    private function compensateFailedApproval(LeaseApplication $application, Lease $lease, string $reviewerUserId): void
    {
        try {
            DB::connection('lease')->transaction(function () use ($application, $lease): void {
                // Hard delete — this lease never validly existed (its creation is
                // being rolled back). A soft delete would leave the row behind,
                // and uq_leases_application_id is a plain unique index that counts
                // soft-deleted rows, so it would permanently block re-approving
                // this application ("duplicate key value violates uq_leases_application_id").
                LeaseHunter::where('lease_id', $lease->id)->forceDelete();
                $lease->forceDelete();
                // Builder update keyed by id — the outer $application instance is
                // stale (still 'pending' in memory after approve() updated a
                // separate instance), so $application->update() would dirty-check
                // to a no-op and leave the row 'approved'. Bypass the model.
                LeaseApplication::where('id', $application->id)->update([
                    'status'              => 'pending',
                    'reviewed_by_user_id' => null,
                    'reviewed_at'         => null,
                ]);
            });

            // Free any exclusive-listing reservation this approval held and
            // re-list it. No-op when the reservation step is what failed (its
            // own transaction rolled back, leaving no row for this lease).
            rescue(fn () => $this->propertyService->releaseBooking($lease->id));

            $this->auditService->log(
                eventType:      'lease_application.approval_compensated',
                sourceDatabase: 'ah_lease',
                tableName:      'lease_applications',
                recordId:       $application->id,
                userId:         $reviewerUserId,
                actionSummary:  'Approval rolled back — signing request creation failed; application returned to pending',
            );
        } catch (\Throwable $e) {
            Log::error('ApplicationService: approval compensation failed', [
                'application_id' => $application->id,
                'lease_id'       => $lease->id,
                'error'          => $e->getMessage(),
            ]);
        }
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
            'status'              => $newStatus,
            'reviewed_by_user_id' => $reviewerUserId,
            'reviewed_at'         => now(),
            'rejection_reason'    => $newStatus === 'rejected' ? $reason : null,
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
