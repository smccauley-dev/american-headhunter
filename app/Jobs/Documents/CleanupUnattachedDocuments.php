<?php

namespace App\Jobs\Documents;

use App\Services\Documents\DocumentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CleanupUnattachedDocuments implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 1;
    public int $timeout = 300;

    public function handle(DocumentService $service): void
    {
        $minutes = (int) env('DOCUMENT_REAPER_TTL_MINUTES', 120);
        $service->reaperCleanup($minutes);
    }
}
