<?php

namespace App\Jobs\Documents;

use App\Services\Documents\DocumentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ScanDocumentForViruses implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(public readonly string $documentId) {}

    public function handle(DocumentService $service): void
    {
        // TODO: integrate with ClamAV or cloud AV scanning service.
        // For now, mark the document as ready (clean) immediately.
        $service->markReady($this->documentId);
    }
}
