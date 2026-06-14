<?php

namespace App\Console\Commands;

use App\Models\Documents\EsignatureRequest;
use App\Services\Lease\LeaseAgreementPdfService;
use Illuminate\Console\Command;

class BackfillSignedLeasePdf extends Command
{
    protected $signature = 'leases:backfill-signed-pdf {--dry-run : List eligible leases without generating PDFs}';

    protected $description = 'Generate and store the executed-lease PDF for completed in-platform leases that have none';

    public function handle(LeaseAgreementPdfService $pdfService): int
    {
        $requests = EsignatureRequest::where('provider', 'in_platform')
            ->where('status', 'completed')
            ->whereNull('signed_document_id')
            ->get();

        if ($requests->isEmpty()) {
            $this->info('No completed in-platform leases are missing a signed PDF.');
            return self::SUCCESS;
        }

        $this->info("Found {$requests->count()} completed in-platform lease(s) without a signed PDF.");

        $generated = 0;
        foreach ($requests as $request) {
            $ref = substr($request->lease_id, 0, 8);

            if ($this->option('dry-run')) {
                $this->line("  [dry-run] lease {$ref} (request {$request->id})");
                continue;
            }

            try {
                $document = $pdfService->generateAndStore($request);
                if ($document !== null) {
                    $generated++;
                    $this->line("  ✓ lease {$ref} → document {$document->id}");
                } else {
                    $this->warn("  · lease {$ref} skipped (not eligible)");
                }
            } catch (\Throwable $e) {
                $this->error("  ✗ lease {$ref}: {$e->getMessage()}");
            }
        }

        if (! $this->option('dry-run')) {
            $this->info("Generated {$generated} signed-lease PDF(s).");
        }

        return self::SUCCESS;
    }
}
