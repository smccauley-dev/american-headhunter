<?php

namespace Tests\Feature\Documents;

use App\Jobs\Documents\CleanupStagedUploads;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * CleanupStagedUploads sweeps abandoned FilePond staging files older than the TTL
 * across all three property staging dirs, and leaves fresh files and non-staging
 * paths untouched. Uses Storage::fake('local') so nothing touches the real disk.
 */
class CleanupStagedUploadsTest extends TestCase
{
    private function age(string $path, int $secondsAgo): void
    {
        // Backdate the underlying file's mtime — what the job compares against.
        touch(Storage::disk('local')->path($path), now()->subSeconds($secondsAgo)->getTimestamp());
    }

    public function test_it_deletes_only_stale_staged_files_and_spares_everything_else(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');

        // One stale + one fresh file in each of the three staging dirs.
        $stale = [
            'pending-ownership-proof/PROP1/old.png',
            'pending-property-photos/PROP2/old.jpg',
            'pending-property-maps/PROP3/old.png',
        ];
        $fresh = [
            'pending-ownership-proof/PROP1/new.png',
            'pending-property-photos/PROP2/new.jpg',
            'pending-property-maps/PROP3/new.png',
        ];
        $unrelated = 'documents/keep.png'; // not a staging dir — must never be touched

        foreach ([...$stale, ...$fresh, $unrelated] as $p) {
            $disk->put($p, 'x');
        }

        // TTL default is 1440 min (24h). Age the stale set to 25h, keep the rest current.
        foreach ($stale as $p) {
            $this->age($p, 25 * 3600);
        }

        (new CleanupStagedUploads)->handle();

        foreach ($stale as $p) {
            $this->assertFalse($disk->exists($p), "stale staged file should be reaped: {$p}");
        }
        foreach ($fresh as $p) {
            $this->assertTrue($disk->exists($p), "fresh staged file should be kept: {$p}");
        }
        $this->assertTrue($disk->exists($unrelated), 'non-staging files must be left alone');
    }
}
