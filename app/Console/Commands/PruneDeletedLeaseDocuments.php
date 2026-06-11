<?php

namespace App\Console\Commands;

use App\Services\Audit\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PruneDeletedLeaseDocuments extends Command
{
    protected $signature   = 'lease:prune-deleted-documents {--dry-run : Report what would be deleted without deleting}';
    protected $description = 'Hard-delete lease_documents soft-deleted more than 30 days ago and clean up orphaned storage files.';

    public function handle(AuditService $auditService): int
    {
        $dryRun    = $this->option('dry-run');
        $threshold = now()->subDays(30);
        $disk      = config('filesystems.defaults.documents', 'local');
        $pruned    = 0;

        $rows = DB::connection('lease')
            ->table('lease_documents')
            ->whereNotNull('deleted_at')
            ->where('deleted_at', '<', $threshold)
            ->get(['id', 'lease_id', 'document_id', 'deleted_at']);

        if ($rows->isEmpty()) {
            $this->info('No lease documents due for pruning.');
            return self::SUCCESS;
        }

        foreach ($rows as $row) {
            // Check if the physical document has any other active references before removing storage
            $otherLeaseRefs = DB::connection('lease')
                ->table('lease_documents')
                ->where('document_id', $row->document_id)
                ->where('id', '!=', $row->id)
                ->whereNull('deleted_at')
                ->exists();

            $esigRefs = DB::connection('documents')
                ->table('esignature_requests')
                ->where(function ($q) use ($row) {
                    $q->where('template_document_id', $row->document_id)
                      ->orWhere('signed_document_id', $row->document_id);
                })
                ->whereNull('deleted_at')
                ->exists();

            $canDeleteFile = ! $otherLeaseRefs && ! $esigRefs;

            if ($dryRun) {
                $action = $canDeleteFile ? 'delete file + record' : 'delete record only (file still referenced)';
                $this->line("  [dry-run] lease_document={$row->id} document={$row->document_id} → {$action}");
                $pruned++;
                continue;
            }

            try {
                if ($canDeleteFile) {
                    // Fetch the storage key from DB 11 and remove the file
                    $doc = DB::connection('documents')
                        ->table('documents')
                        ->where('id', $row->document_id)
                        ->whereNull('deleted_at')
                        ->first(['id', 'storage_key']);

                    if ($doc) {
                        Storage::disk($disk)->delete($doc->storage_key);

                        DB::connection('documents')
                            ->table('documents')
                            ->where('id', $doc->id)
                            ->update(['deleted_at' => now()]);
                    }
                }

                // Hard-delete the lease_documents row
                DB::connection('lease')
                    ->table('lease_documents')
                    ->where('id', $row->id)
                    ->delete();

                try {
                    $auditService->log(
                        eventType:      'document.pruned',
                        sourceDatabase: 'ah_lease',
                        tableName:      'lease_documents',
                        recordId:       $row->id,
                        actionSummary:  'Lease document hard-deleted by 30-day prune job' . ($canDeleteFile ? ' (file removed)' : ' (file retained — other references exist)'),
                    );
                } catch (\Throwable) {}

                $pruned++;
            } catch (\Throwable $e) {
                Log::error('lease:prune-deleted-documents — failed to prune row', [
                    'lease_document_id' => $row->id,
                    'error'             => $e->getMessage(),
                ]);
            }
        }

        $verb = $dryRun ? 'Would prune' : 'Pruned';
        $this->info("{$verb} {$pruned} lease document(s).");

        return self::SUCCESS;
    }
}
