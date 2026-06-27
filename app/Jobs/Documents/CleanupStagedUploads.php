<?php

namespace App\Jobs\Documents;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Scheduled reaper for FilePond staging dirs — the layer *before* a Document exists.
 *
 * The property uploaders (ownership proof, photos, map images) temp-stage each dropped
 * file under pending-*\/{property}\/ on the local disk and only commit it to a Document
 * on submit. Files from an abandoned upload (user reloads or closes before submitting)
 * are otherwise only cleaned by each controller's pruneTemp(), which runs lazily — only
 * when that same property is uploaded to again. This sweeps every staging dir on a fixed
 * cadence so abandoned temp files can't linger indefinitely.
 *
 * It is the staging-dir sibling of CleanupUnattachedDocuments (which reaps committed-but-
 * unattached Document rows). Committed proof is never touched — these dirs only ever hold
 * pre-submit temp files.
 */
class CleanupStagedUploads implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 1;
    public int $timeout = 300;

    /** Staging dir prefixes on the local disk — must match each controller's tempDir(). */
    private const STAGING_DIRS = [
        'pending-ownership-proof',
        'pending-property-photos',
        'pending-property-maps',
    ];

    public function handle(): void
    {
        $cutoff = now()->subMinutes((int) env('STAGED_UPLOAD_TTL_MINUTES', 1440))->getTimestamp();
        $disk   = Storage::disk('local');

        foreach (self::STAGING_DIRS as $prefix) {
            foreach ($disk->allFiles($prefix) as $file) {
                if ($disk->lastModified($file) < $cutoff) {
                    $disk->delete($file);
                }
            }
        }
    }
}
