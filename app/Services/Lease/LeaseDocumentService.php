<?php

namespace App\Services\Lease;

use App\Models\Lease\LeaseDocument;
use App\Services\Audit\AuditService;
use App\Services\BaseService;
use App\Services\Documents\DocumentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class LeaseDocumentService extends BaseService
{
    public function __construct(
        private readonly DocumentService $documentService,
        private readonly AuditService    $auditService,
    ) {}

    // ── Read ──────────────────────────────────────────────────────────────────

    /**
     * Return all non-deleted lease_documents for a lease, with document metadata joined.
     * Returns a Collection of plain objects (not Eloquent models) so callers can hydrate
     * the display without an extra round-trip.
     */
    public function getForLease(string $leaseId): Collection
    {
        $rows = LeaseDocument::where('lease_id', $leaseId)
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->get();

        if ($rows->isEmpty()) {
            return collect();
        }

        $docIds = $rows->pluck('document_id')->all();

        $docs = DB::connection('documents')
            ->table('documents')
            ->whereIn('id', $docIds)
            ->get(['id', 'original_filename', 'size_bytes', 'created_at'])
            ->keyBy('id');

        return $rows->map(function (LeaseDocument $ld) use ($docs) {
            $doc = $docs->get($ld->document_id);
            return (object) [
                'id'               => $ld->id,
                'lease_id'         => $ld->lease_id,
                'document_id'      => $ld->document_id,
                'tag'              => $ld->tag,
                'notes'            => $ld->notes,
                'original_filename' => $doc?->original_filename,
                'size_bytes'       => $doc?->size_bytes,
                'created_at'       => $ld->created_at,
            ];
        });
    }

    // ── Writes ────────────────────────────────────────────────────────────────

    /**
     * Store a file via DocumentService and create the lease_documents association.
     * Audit-logs the upload event.
     */
    public function upload(
        string $leaseId,
        UploadedFile $file,
        string $tag,
        string $uploadedByUserId,
        ?string $notes = null,
    ): LeaseDocument {
        $document = $this->documentService->storeUploadedFile(
            $file,
            $uploadedByUserId,
            'contract',
        );

        $leaseDoc = LeaseDocument::create([
            'lease_id'           => $leaseId,
            'document_id'        => $document->id,
            'tag'                => $tag,
            'uploaded_by_user_id' => $uploadedByUserId,
            'notes'              => $notes,
        ]);

        try {
            $this->auditService->log(
                eventType:      'document.uploaded',
                sourceDatabase: 'ah_lease',
                tableName:      'lease_documents',
                recordId:       $leaseDoc->id,
                actionSummary:  "Lease document uploaded: tag={$tag}, lease={$leaseId}",
                userId:         $uploadedByUserId,
            );
        } catch (\Throwable) {}

        return $leaseDoc;
    }

    /**
     * Soft-delete a lease document association (file remains in DB 11).
     */
    public function remove(string $leaseDocumentId, string $removedByUserId): void
    {
        LeaseDocument::where('id', $leaseDocumentId)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now()]);

        try {
            $this->auditService->log(
                eventType:      'document.removed',
                sourceDatabase: 'ah_lease',
                tableName:      'lease_documents',
                recordId:       $leaseDocumentId,
                actionSummary:  'Lease document association removed (soft delete)',
                userId:         $removedByUserId,
            );
        } catch (\Throwable) {}
    }

    /**
     * Serve a lease document for download, validating that the given user
     * is a party to the lease (lessor or lessee).
     *
     * Returns the storage response (StreamedResponse) or throws on access denial.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function authorizedDownload(
        string $leaseDocumentId,
        string $leaseId,
        string $requestingUserId,
    ): \Symfony\Component\HttpFoundation\StreamedResponse {
        // Verify the lease document belongs to this lease and is not deleted
        $leaseDoc = LeaseDocument::where('id', $leaseDocumentId)
            ->where('lease_id', $leaseId)
            ->whereNull('deleted_at')
            ->firstOrFail();

        // Verify the requesting user is a party to the lease
        $lease = \App\Models\Lease\Lease::where('id', $leaseId)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($requestingUserId) {
                $q->where('lessee_user_id', $requestingUserId)
                  ->orWhere('lessor_user_id', $requestingUserId);
            })
            ->first();

        if (! $lease) {
            abort(403, 'You are not authorized to download this document.');
        }

        // Fetch the document record from DB 11
        $doc = DB::connection('documents')
            ->table('documents')
            ->where('id', $leaseDoc->document_id)
            ->whereNull('deleted_at')
            ->first();

        if (! $doc) {
            abort(404, 'Document not found.');
        }

        // Audit the download
        try {
            $this->auditService->log(
                eventType:      'document.downloaded',
                sourceDatabase: 'ah_lease',
                tableName:      'lease_documents',
                recordId:       $leaseDocumentId,
                actionSummary:  "Lease document downloaded by user={$requestingUserId}",
                userId:         $requestingUserId,
            );
        } catch (\Throwable) {}

        $disk = config('filesystems.defaults.documents', 'local');

        return Storage::disk($disk)->download(
            $doc->storage_key,
            $doc->original_filename ?? 'document.pdf',
        );
    }

    /**
     * Admin download — no lease-party check, but still logs the audit event.
     */
    public function adminDownload(
        string $leaseDocumentId,
        string $adminUserId,
    ): \Symfony\Component\HttpFoundation\StreamedResponse {
        $leaseDoc = LeaseDocument::where('id', $leaseDocumentId)
            ->whereNull('deleted_at')
            ->firstOrFail();

        $doc = DB::connection('documents')
            ->table('documents')
            ->where('id', $leaseDoc->document_id)
            ->whereNull('deleted_at')
            ->first();

        if (! $doc) {
            abort(404, 'Document not found.');
        }

        try {
            $this->auditService->log(
                eventType:      'document.downloaded',
                sourceDatabase: 'ah_lease',
                tableName:      'lease_documents',
                recordId:       $leaseDocumentId,
                actionSummary:  "Lease document downloaded by admin user={$adminUserId}",
                userId:         $adminUserId,
            );
        } catch (\Throwable) {}

        $disk = config('filesystems.defaults.documents', 'local');

        return Storage::disk($disk)->download(
            $doc->storage_key,
            $doc->original_filename ?? 'document.pdf',
        );
    }
}
