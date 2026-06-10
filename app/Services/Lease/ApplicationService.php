<?php

namespace App\Services\Lease;

use App\Models\Identity\HunterCredentials;
use App\Models\Lease\LeaseApplication;
use App\Models\Lease\LeaseApplicationHunter;
use App\Models\Lease\LeaseApplicationReviewHistory;
use App\Services\Audit\AuditService;
use App\Services\BaseService;
use App\Services\Documents\DocumentService;
use App\Services\Platform\LegalService;
use App\Services\Property\PropertyService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ApplicationService extends BaseService
{
    public function __construct(
        private readonly AuditService    $auditService,
        private readonly PropertyService $propertyService,
        private readonly DocumentService $documentService,
        private readonly LegalService    $legalService,
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
