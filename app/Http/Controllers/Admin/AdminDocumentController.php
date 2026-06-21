<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Documents\Document;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves documents from the private documents store to admin staff. The routes
 * are gated by ['db.system','auth:web']; the web guard only resolves users that
 * pass User::canAccessPanel() (staff/admin roles — landowners use a separate
 * session guard), so these are effectively staff-only.
 *
 * SEC-050: every access is audit-logged. These documents include applicant PII
 * (driver's-license and hunting-license images shown in the admin hunter
 * roster); the previous inline closures left no trail of who viewed them.
 */
class AdminDocumentController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function view(string $documentId): StreamedResponse
    {
        $doc = $this->resolve($documentId, 'document.viewed', 'viewed inline');

        return Storage::disk($this->disk())->response(
            $doc->storage_key,
            $doc->original_filename,
            ['Content-Type' => $doc->mime_type ?? 'application/octet-stream'],
        );
    }

    public function download(string $documentId): StreamedResponse
    {
        $doc = $this->resolve($documentId, 'document.downloaded', 'downloaded');

        return Storage::disk($this->disk())->download(
            $doc->storage_key,
            $doc->original_filename ?? 'document.pdf',
        );
    }

    private function resolve(string $documentId, string $eventType, string $verb): Document
    {
        $doc = Document::on('documents')->findOrFail($documentId);

        $actorId = (string) auth()->id();
        $this->audit->log(
            eventType:      $eventType,
            sourceDatabase: 'ah_documents',
            tableName:      'documents',
            recordId:       $documentId,
            userId:         $actorId,
            actionSummary:  "Document {$verb} by admin user={$actorId}",
        );

        return $doc;
    }

    private function disk(): string
    {
        return config('filesystems.defaults.documents', 'local');
    }
}
