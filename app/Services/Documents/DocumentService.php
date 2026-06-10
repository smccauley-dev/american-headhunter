<?php

namespace App\Services\Documents;

use App\Jobs\Documents\ScanDocumentForViruses;
use App\Models\Documents\Document;
use App\Models\Documents\QrCode;
use App\Services\BaseService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentService extends BaseService
{
    // ── Read ──────────────────────────────────────────────────────────────────

    public function findOrFail(string $documentId): Document
    {
        return Document::findOrFail($documentId);
    }

    public function getForOwner(string $ownerUserId, ?string $documentType = null): \Illuminate\Support\Collection
    {
        $query = Document::where('owner_user_id', $ownerUserId)
            ->where('status', '!=', 'unattached');

        if ($documentType !== null) {
            $query->where('document_type', $documentType);
        }

        return $query->orderByDesc('created_at')->get();
    }

    // ── Writes ────────────────────────────────────────────────────────────────

    /**
     * Register a file upload that has already been placed in object storage.
     *
     * When $unattached is true the document is created in 'unattached' status and
     * no virus scan is dispatched. The caller must later call attachDocuments() after
     * its own transaction commits to promote the record to 'processing'.
     */
    public function register(
        string $ownerUserId,
        string $documentType,
        string $originalFilename,
        string $mimeType,
        int $sizeBytes,
        string $storageBucket,
        string $storageKey,
        string $storageProvider = 'garage',
        ?string $checksum = null,
        bool $unattached = false,
    ): Document {
        $document = Document::create([
            'owner_user_id'    => $ownerUserId,
            'document_type'    => $documentType,
            'status'           => $unattached ? 'unattached' : 'processing',
            'original_filename' => $originalFilename,
            'mime_type'        => $mimeType,
            'size_bytes'       => $sizeBytes,
            'storage_bucket'   => $storageBucket,
            'storage_key'      => $storageKey,
            'storage_provider' => $storageProvider,
            'checksum_sha256'  => $checksum,
            'virus_scan_status' => 'pending',
            'is_public'        => false,
        ]);

        if (! $unattached) {
            ScanDocumentForViruses::dispatch($document->id);
        }

        return $document;
    }

    public function markReady(string $documentId): void
    {
        Document::findOrFail($documentId)->update([
            'status'            => 'ready',
            'virus_scan_status' => 'clean',
            'virus_scanned_at'  => now(),
        ]);
    }

    public function markQuarantined(string $documentId): void
    {
        Document::findOrFail($documentId)->update([
            'status'            => 'quarantined',
            'virus_scan_status' => 'infected',
            'virus_scanned_at'  => now(),
        ]);
    }

    public function softDelete(string $documentId): void
    {
        Document::findOrFail($documentId)->delete();
    }

    /**
     * Accept an uploaded file, persist it to object storage, and create the Document record.
     * The disk is driven by the DOCUMENTS_DISK env var (defaults to 'local' for dev).
     *
     * When $unattached is true the document is held in 'unattached' status — the caller
     * must promote it via attachDocuments() after its own transaction commits.
     */
    public function storeUploadedFile(
        UploadedFile $file,
        string $ownerUserId,
        string $documentType,
        bool $unattached = false,
    ): Document {
        $disk = config('filesystems.defaults.documents', 'local');
        $ext  = $file->getClientOriginalExtension() ?: 'bin';
        $key  = "{$ownerUserId}/" . Str::uuid() . ".{$ext}";

        Storage::disk($disk)->putFileAs('documents', $file, $key);

        // Map local-driver disks (dev/test) to the canonical 'garage' provider name
        // so the storage_provider CHECK constraint ('garage', 'azure_blob') is satisfied.
        $provider = $disk === 'azure_blob' ? 'azure_blob' : 'garage';

        return $this->register(
            ownerUserId:      $ownerUserId,
            documentType:     $documentType,
            originalFilename: $file->getClientOriginalName(),
            mimeType:         $file->getMimeType() ?? 'application/octet-stream',
            sizeBytes:        $file->getSize(),
            storageBucket:    config("filesystems.disks.{$disk}.bucket", 'local'),
            storageKey:       "documents/{$key}",
            storageProvider:  $provider,
            checksum:         hash_file('sha256', $file->getRealPath()),
            unattached:       $unattached,
        );
    }

    /**
     * Promote previously-unattached documents to 'processing' and dispatch virus scans.
     * Called after the caller's own transaction commits successfully.
     */
    public function attachDocuments(array $documentIds): void
    {
        foreach (array_filter($documentIds) as $id) {
            $updated = Document::on('documents')
                ->where('id', $id)
                ->where('status', 'unattached')
                ->update(['status' => 'processing']);

            if ($updated) {
                ScanDocumentForViruses::dispatch($id);
            }
        }
    }

    /**
     * Immediate compensation: soft-delete unattached documents and remove their storage files.
     * Called in a catch block when a surrounding transaction fails. The reaper is the
     * authoritative cleanup for cases where this compensation doesn't run (process death).
     */
    public function deleteUnattachedByIds(array $documentIds): void
    {
        $ids = array_values(array_filter($documentIds));
        if (empty($ids)) {
            return;
        }

        $disk = config('filesystems.defaults.documents', 'local');
        $now  = now()->toDateTimeString();

        $docs = DB::connection('documents')->table('documents')
            ->whereIn('id', $ids)
            ->where('status', 'unattached')
            ->whereNull('deleted_at')
            ->get(['id', 'storage_key']);

        foreach ($docs as $doc) {
            Storage::disk($disk)->delete($doc->storage_key);
            DB::connection('documents')->table('documents')
                ->where('id', $doc->id)
                ->whereNull('deleted_at')
                ->update(['deleted_at' => $now]);
        }
    }

    /**
     * Scheduled reaper: remove unattached documents older than $olderThanMinutes.
     * Deletes both the storage object and soft-deletes the DB record.
     * Returns the count of documents cleaned.
     */
    public function reaperCleanup(int $olderThanMinutes = 120): int
    {
        $disk      = config('filesystems.defaults.documents', 'local');
        $threshold = now()->subMinutes($olderThanMinutes)->toDateTimeString();
        $now       = now()->toDateTimeString();
        $cleaned   = 0;

        $docs = DB::connection('documents')->table('documents')
            ->where('status', 'unattached')
            ->where('created_at', '<', $threshold)
            ->whereNull('deleted_at')
            ->get(['id', 'storage_key']);

        foreach ($docs as $doc) {
            Storage::disk($disk)->delete($doc->storage_key);
            DB::connection('documents')->table('documents')
                ->where('id', $doc->id)
                ->whereNull('deleted_at')
                ->update(['deleted_at' => $now]);
            $cleaned++;
        }

        return $cleaned;
    }

    // ── QR Codes ──────────────────────────────────────────────────────────────

    public function createQrCode(
        string $codeType,
        string $targetId,
        string $targetType,
        ?string $documentId = null,
        ?\DateTimeInterface $expiresAt = null,
    ): QrCode {
        $qrCode = QrCode::create([
            'code_type'   => $codeType,
            'target_id'   => $targetId,
            'target_type' => $targetType,
            'token'       => Str::random(40),
            'document_id' => $documentId,
            'expires_at'  => $expiresAt,
            'scan_count'  => 0,
        ]);

        \App\Jobs\Documents\GenerateQrCodeImage::dispatch($qrCode->id);

        return $qrCode;
    }

    public function resolveQrToken(string $token): ?QrCode
    {
        $qrCode = QrCode::where('token', $token)->whereNull('deleted_at')->first();

        if ($qrCode === null || $qrCode->isExpired()) {
            return null;
        }

        $qrCode->increment('scan_count');
        $qrCode->update(['last_scanned_at' => now()]);

        return $qrCode;
    }
}
