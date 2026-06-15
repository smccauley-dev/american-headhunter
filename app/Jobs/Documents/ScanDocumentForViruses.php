<?php

namespace App\Jobs\Documents;

use App\Services\Audit\AuditService;
use App\Services\Documents\DocumentService;
use App\Services\Documents\VirusScanService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ScanDocumentForViruses implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(public readonly string $documentId) {}

    public function handle(DocumentService $documents, VirusScanService $scanner): void
    {
        // No AV configured (dev/test) — accept the file as-is.
        if (! $scanner->enabled()) {
            $documents->markReady($this->documentId);
            return;
        }

        $document = $documents->findOrFail($this->documentId);
        $disk     = config('filesystems.defaults.documents', 'local');
        $bytes    = Storage::disk($disk)->get($document->storage_key);

        // File missing — let the job retry rather than mark an unscanned file ready.
        if ($bytes === null) {
            throw new \RuntimeException(
                "ScanDocumentForViruses: file not found for document {$this->documentId} at {$document->storage_key}"
            );
        }

        // Throws on scanner error → job retries; an unknown result never marks the file ready.
        $result = $scanner->scan($bytes);

        if ($result === VirusScanService::INFECTED) {
            $documents->markQuarantined($this->documentId);

            Log::warning('Document quarantined: virus scan detected a threat', [
                'document_id' => $this->documentId,
            ]);

            app(AuditService::class)->log(
                eventType:      'document.quarantined',
                sourceDatabase: 'ah_documents',
                tableName:      'documents',
                recordId:       $this->documentId,
                actionSummary:  'Document quarantined — virus scan detected a threat',
            );

            return;
        }

        $documents->markReady($this->documentId);
    }
}
