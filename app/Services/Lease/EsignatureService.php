<?php

namespace App\Services\Lease;

use App\Models\Documents\Document;
use App\Models\Documents\EsignatureRequest;
use App\Models\Documents\EsignatureSigner;
use App\Models\Lease\Lease;
use App\Models\Lease\LeaseHunter;
use App\Models\Lease\SignatureEvent;
use App\Services\Audit\AuditService;
use App\Services\BaseService;
use App\Support\Entitlements;
use Illuminate\Support\Facades\Log;

class EsignatureService extends BaseService
{
    public function __construct(
        private readonly LeaseService      $leaseService,
        private readonly AuditService      $auditService,
        private readonly DropboxSignService $dropboxSign,
    ) {}

    // ── Read ──────────────────────────────────────────────────────────────────

    public function getRequestForLease(string $leaseId): ?EsignatureRequest
    {
        return EsignatureRequest::where('lease_id', $leaseId)
            ->latest('requested_at')
            ->first();
    }

    public function signerForUser(string $requestId, string $userId): ?EsignatureSigner
    {
        return EsignatureSigner::where('request_id', $requestId)
            ->where('user_id', $userId)
            ->first();
    }

    /** Whether any party has already signed the lease's latest signing request. */
    public function hasAnySignature(string $leaseId): bool
    {
        $request = $this->getRequestForLease($leaseId);

        return $request !== null
            && EsignatureSigner::where('request_id', $request->id)
                ->where('status', 'signed')
                ->exists();
    }

    /**
     * Document id of the fully-executed lease PDF, if one exists. Stored by the
     * Dropbox Sign webhook on completion; in-platform signing produces no PDF.
     */
    public function signedLeaseDocumentId(string $leaseId): ?string
    {
        return EsignatureRequest::where('lease_id', $leaseId)
            ->whereNotNull('signed_document_id')
            ->latest('completed_at')
            ->value('signed_document_id');
    }

    /**
     * Stream the fully-executed lease PDF to a party of the lease. Works on
     * leases of any status (active, expired, terminated) so a lessee can always
     * retrieve their signed agreement.
     */
    public function downloadSignedLease(string $leaseId, string $requestingUserId): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $lease = Lease::where('id', $leaseId)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($requestingUserId) {
                $q->where('lessee_user_id', $requestingUserId)
                  ->orWhere('lessor_user_id', $requestingUserId);
            })
            ->first();

        abort_if($lease === null, 403, 'You are not authorized to download this document.');

        $documentId = $this->signedLeaseDocumentId($leaseId);
        abort_if($documentId === null, 404, 'No signed lease is available for this lease.');

        $doc = Document::on('documents')->whereNull('deleted_at')->find($documentId);
        abort_if($doc === null, 404, 'Document not found.');

        try {
            $this->auditService->log(
                eventType:      'document.downloaded',
                sourceDatabase: 'ah_documents',
                tableName:      'documents',
                recordId:       $documentId,
                actionSummary:  "Signed lease downloaded by user={$requestingUserId}",
                userId:         $requestingUserId,
            );
        } catch (\Throwable) {}

        $disk = config('filesystems.defaults.documents', 'local');

        return \Illuminate\Support\Facades\Storage::disk($disk)->download(
            $doc->storage_key,
            $doc->original_filename ?? 'signed_lease.pdf',
        );
    }

    // ── Writes ────────────────────────────────────────────────────────────────

    /**
     * Create a signing request for a lease.
     *
     * When $customPdf is provided and the lessor has the custom_lease_template entitlement,
     * the request is routed to Dropbox Sign (embedded signing of the uploaded PDF).
     * Otherwise falls back to in-platform signing.
     *
     * @param  array{user_id: string, name: string, email: string}  $lessorInfo
     * @param  array{user_id: string, name: string, email: string}  $lesseeInfo
     */
    public function createRequest(
        Lease    $lease,
        string   $requestedByUserId,
        array    $lessorInfo,
        array    $lesseeInfo,
        ?Document $customPdf = null,
    ): EsignatureRequest {
        if ($customPdf !== null) {
            $lessorUser = \App\Models\Identity\User::on('identity')->find($lessorInfo['user_id']);
            $hasEntitlement = $lessorUser !== null
                && app(\App\Services\Platform\EntitlementService::class)
                    ->can($lessorUser, Entitlements::CUSTOM_LEASE_TEMPLATE);

            if ($hasEntitlement) {
                return $this->createDropboxSignRequest($lease, $requestedByUserId, $lessorInfo, $lesseeInfo, $customPdf);
            }
        }

        return $this->createInPlatformRequest($lease, $requestedByUserId, $lessorInfo, $lesseeInfo, $customPdf);
    }

    private function createInPlatformRequest(
        Lease     $lease,
        string    $requestedByUserId,
        array     $lessorInfo,
        array     $lesseeInfo,
        ?Document $customPdf = null,
    ): EsignatureRequest {
        $year = $lease->start_date?->format('Y') ?? now()->year;

        $request = EsignatureRequest::create([
            'lease_id'             => $lease->id,
            'requester_user_id'    => $requestedByUserId,
            'provider'             => 'in_platform',
            'status'               => 'out_for_signature',
            'subject'              => "Hunting Lease Agreement — {$year}",
            'template_document_id' => $customPdf?->id,
            'requested_at'         => now(),
        ]);

        EsignatureSigner::create([
            'request_id' => $request->id,
            'user_id'    => $lessorInfo['user_id'],
            'email'      => $lessorInfo['email'],
            'name'       => $lessorInfo['name'],
            'order_num'  => 1,
            'status'     => 'pending',
        ]);

        EsignatureSigner::create([
            'request_id' => $request->id,
            'user_id'    => $lesseeInfo['user_id'],
            'email'      => $lesseeInfo['email'],
            'name'       => $lesseeInfo['name'],
            'order_num'  => 2,
            'status'     => 'pending',
        ]);

        SignatureEvent::create([
            'lease_id'    => $lease->id,
            'user_id'     => $requestedByUserId,
            'provider'    => 'in_platform',
            'event_type'  => 'sent',
            'occurred_at' => now(),
        ]);

        return $request;
    }

    private function createDropboxSignRequest(
        Lease    $lease,
        string   $requestedByUserId,
        array    $lessorInfo,
        array    $lesseeInfo,
        Document $customPdf,
    ): EsignatureRequest {
        $year    = $lease->start_date?->format('Y') ?? now()->year;
        $subject = "Hunting Lease Agreement — {$year}";

        // Resolve the stored PDF to a temp file path for the Dropbox Sign upload
        $disk    = config('filesystems.defaults.documents', 'local');
        $tmpPath = tempnam(sys_get_temp_dir(), 'dbs_') . '.pdf';
        file_put_contents($tmpPath, \Illuminate\Support\Facades\Storage::disk($disk)->get($customPdf->storage_key));

        try {
            $envelope = $this->dropboxSign->createEmbeddedEnvelope(
                $tmpPath,
                $subject,
                $lessorInfo,
                $lesseeInfo,
            );
        } finally {
            @unlink($tmpPath);
        }

        $request = EsignatureRequest::create([
            'lease_id'                      => $lease->id,
            'requester_user_id'             => $requestedByUserId,
            'provider'                      => 'dropbox_sign',
            'provider_signature_request_id' => $envelope['signature_request_id'],
            'status'                        => 'out_for_signature',
            'subject'                       => $subject,
            'template_document_id'          => $customPdf->id,
            'requested_at'                  => now(),
        ]);

        EsignatureSigner::create([
            'request_id'         => $request->id,
            'user_id'            => $lessorInfo['user_id'],
            'email'              => $lessorInfo['email'],
            'name'               => $lessorInfo['name'],
            'order_num'          => 1,
            'status'             => 'pending',
            'provider_signer_id' => $envelope['lessor_signature_id'],
        ]);

        EsignatureSigner::create([
            'request_id'         => $request->id,
            'user_id'            => $lesseeInfo['user_id'],
            'email'              => $lesseeInfo['email'],
            'name'               => $lesseeInfo['name'],
            'order_num'          => 2,
            'status'             => 'pending',
            'provider_signer_id' => $envelope['lessee_signature_id'],
        ]);

        SignatureEvent::create([
            'lease_id'    => $lease->id,
            'user_id'     => $requestedByUserId,
            'provider'    => 'dropbox_sign',
            'event_type'  => 'sent',
            'occurred_at' => now(),
        ]);

        return $request;
    }

    /**
     * Record an in-platform signature for a user.
     * Returns true if this was the final signature and the lease was activated.
     *
     * Pass $recordedByUserId when someone other than the signer executes the
     * signature (admin signing on a landowner's behalf) — the acting user is
     * written to the audit log so the signature is never silently attributed
     * to a party who didn't perform the action.
     */
    public function recordSignature(
        string $requestId,
        string $userId,
        string $ipAddress = '',
        string $userAgent = '',
        ?string $recordedByUserId = null,
    ): bool {
        $signer = EsignatureSigner::where('request_id', $requestId)
            ->where('user_id', $userId)
            ->first();

        if (! $signer || $signer->status === 'signed') {
            return false;
        }

        $signer->status    = 'signed';
        $signer->signed_at = now();
        $signer->save();

        $request = EsignatureRequest::findOrFail($requestId);

        // Permanent event in DB 3
        SignatureEvent::create([
            'lease_id'    => $request->lease_id,
            'user_id'     => $userId,
            'provider'    => 'in_platform',
            'event_type'  => 'signed',
            'occurred_at' => now(),
            'ip_address'  => $ipAddress ?: null,
            'user_agent'  => $userAgent ?: null,
        ]);

        if ($recordedByUserId !== null && $recordedByUserId !== $userId) {
            $this->auditService->log(
                eventType:      'esignature.signed_on_behalf',
                sourceDatabase: 'ah_lease',
                tableName:      'signature_events',
                recordId:       $request->lease_id,
                userId:         $recordedByUserId,
                ipAddress:      $ipAddress ?: null,
                userAgent:      $userAgent ?: null,
                actionSummary:  "In-platform signature recorded on behalf of user {$userId}",
                newValues:      ['signer_user_id' => $userId, 'request_id' => $requestId],
            );
        }

        return $this->activateIfComplete($request);
    }

    /**
     * Void the lease's latest signing request (e.g. when an approval is
     * overridden before anyone has signed). No-op once completed or cancelled.
     */
    public function cancelRequest(string $leaseId, string $cancelledByUserId): void
    {
        $request = $this->getRequestForLease($leaseId);
        if (! $request || in_array($request->status, ['completed', 'cancelled'], true)) {
            return;
        }

        $request->status = 'cancelled';
        $request->save();

        SignatureEvent::create([
            'lease_id'    => $leaseId,
            'user_id'     => $cancelledByUserId,
            'provider'    => $request->provider,
            'event_type'  => 'cancelled',
            'occurred_at' => now(),
        ]);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function activateIfComplete(EsignatureRequest $request): bool
    {
        if (! $request->allSigned()) {
            return false;
        }

        $request->status       = 'completed';
        $request->completed_at = now();
        $request->save();

        // Permanent completion event in DB 3
        SignatureEvent::create([
            'lease_id'    => $request->lease_id,
            'user_id'     => $request->requester_user_id,
            'provider'    => 'in_platform',
            'event_type'  => 'completed',
            'occurred_at' => now(),
        ]);

        // Activate the lease record
        $this->leaseService->activate($request->lease_id);

        // Approve the primary lessee in lease_hunters
        LeaseHunter::where('lease_id', $request->lease_id)
            ->where('role', 'primary')
            ->update(['is_approved' => true, 'approved_at' => now()]);

        // Generate and store the executed-lease PDF so both parties can download
        // their signed agreement. Never let a PDF failure block lease activation.
        try {
            app(LeaseAgreementPdfService::class)->generateAndStore($request);
        } catch (\Throwable $e) {
            Log::error('Failed to generate signed-lease PDF', [
                'request_id' => $request->id,
                'error'      => $e->getMessage(),
            ]);
        }

        try {
            $this->auditService->log(
                eventType:      'lease.activated',
                sourceDatabase: 'ah_lease',
                tableName:      'leases',
                recordId:       $request->lease_id,
                actionSummary:  'Lease activated after all in-platform signatures collected',
            );
        } catch (\Throwable) {}

        return true;
    }
}
