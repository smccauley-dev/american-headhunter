<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Services\Property\PropertyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Landowner front-end property photos (member portal) — the Photos tab of the
 * details hub. Upload, caption, set-primary, reorder and soft-delete, all scoped
 * through PropertyService::userCanManageProperty (the properties table has no RLS
 * policy); mutations on a single photo are additionally re-scoped to the property
 * so a foreign photo id 404s into a no-op. Redirects back so the active tab and
 * scroll position are preserved.
 *
 * Uploads mirror the admin FilePond flow: each file is temp-uploaded the moment
 * it is dropped (tempStore → a token under pending-property-photos/{property}),
 * revertable (tempRevert), and committed as a batch on Submit (store consumes the
 * tokens). The temp dir layout matches the admin uploader's so addPhoto is fed an
 * UploadedFile reconstructed from the staged file exactly as the admin action does.
 */
class PropertyPhotoController extends Controller
{
    public function __construct(private readonly PropertyService $properties) {}

    /** FilePond process: stage one dropped file, return its opaque token. */
    public function tempStore(Request $request, string $property): Response
    {
        $this->authorizeManage($property);

        $request->validate([
            'photo' => 'required|image|max:10240', // 10 MB — matches the admin uploader
        ]);

        $this->pruneTemp($property);

        $path = $request->file('photo')->store($this->tempDir($property), 'local');

        // FilePond stores this token and echoes it back on revert and on submit.
        return response(basename($path))->header('Content-Type', 'text/plain');
    }

    /** FilePond revert: drop a staged file the user removed before submitting. */
    public function tempRevert(Request $request, string $property): Response
    {
        $this->authorizeManage($property);

        $this->deleteTemp($property, trim($request->getContent()));

        return response('');
    }

    public function store(Request $request, string $property): RedirectResponse
    {
        $this->authorizeManage($property);

        $data = $request->validate([
            'tmp_files'   => 'required|array|max:20',
            'tmp_files.*' => 'string',
            'caption'     => 'nullable|string|max:255',
            'import_exif' => 'boolean',
        ]);

        $uploaded = 0;
        foreach ($data['tmp_files'] as $token) {
            $path = $this->tempDir($property) . '/' . basename($token);
            if (! Storage::disk('local')->exists($path)) {
                continue; // stale/foreign token — skip rather than fail the batch
            }
            try {
                $file = new UploadedFile(
                    Storage::disk('local')->path($path),
                    basename($path),
                    Storage::disk('local')->mimeType($path) ?: 'image/jpeg',
                    null,
                    true,
                );
                $this->properties->addPhoto(
                    $property,
                    $file,
                    $data['caption'] ?? null,
                    [],
                    (bool) ($data['import_exif'] ?? true),
                );
                $uploaded++;
            } catch (\Throwable $e) {
                report($e);
            } finally {
                Storage::disk('local')->delete($path);
            }
        }

        return back()->with(
            $uploaded > 0 ? 'success' : 'error',
            $uploaded > 0
                ? ($uploaded === 1 ? 'Photo uploaded.' : "{$uploaded} photos uploaded.")
                : 'Photo upload failed.',
        );
    }

    public function update(Request $request, string $property, string $photo): RedirectResponse
    {
        $this->authorizeOwnsPhoto($property, $photo);

        $data = $request->validate([
            'caption'    => 'nullable|string|max:255',
            'tags'       => 'array',
            'tags.*'     => 'string|max:40',
            'latitude'   => 'nullable|numeric|min:-90|max:90',
            'longitude'  => 'nullable|numeric|min:-180|max:180',
            'is_primary' => 'boolean',
        ]);

        $this->properties->updatePhotoDetails(
            $photo,
            $data['caption'] ?? null,
            $data['tags'] ?? [],
            isset($data['latitude']) ? (float) $data['latitude'] : null,
            isset($data['longitude']) ? (float) $data['longitude'] : null,
        );

        // Promote to cover only when a non-primary photo is toggled on (a photo
        // can't un-primary itself — another photo must be set as primary instead).
        if (! empty($data['is_primary'])) {
            $this->properties->setPrimaryPhoto($photo);
        }

        return back()->with('success', 'Photo updated.');
    }

    public function setPrimary(string $property, string $photo): RedirectResponse
    {
        $this->authorizeOwnsPhoto($property, $photo);

        $this->properties->setPrimaryPhoto($photo);

        return back()->with('success', 'Cover photo updated.');
    }

    public function move(Request $request, string $property, string $photo): RedirectResponse
    {
        $this->authorizeOwnsPhoto($property, $photo);

        $data = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]]);

        $this->properties->movePhoto($photo, $data['direction']);

        return back();
    }

    public function destroy(string $property, string $photo): RedirectResponse
    {
        $this->authorizeOwnsPhoto($property, $photo);

        $this->properties->deletePhoto($photo);

        return back()->with('success', 'Photo deleted.');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Per-property staging dir, shared shape with the admin uploader. */
    private function tempDir(string $propertyId): string
    {
        return 'pending-property-photos/' . basename($propertyId);
    }

    /** Delete a single staged file by token (basename-only, no traversal). */
    private function deleteTemp(string $propertyId, string $token): void
    {
        $name = basename($token);
        if ($name === '') {
            return;
        }
        $path = $this->tempDir($propertyId) . '/' . $name;
        if (Storage::disk('local')->exists($path)) {
            Storage::disk('local')->delete($path);
        }
    }

    /** Sweep staged files older than a day so abandoned drops don't accumulate. */
    private function pruneTemp(string $propertyId): void
    {
        $cutoff = now()->subDay()->getTimestamp();
        foreach (Storage::disk('local')->files($this->tempDir($propertyId)) as $file) {
            if (Storage::disk('local')->lastModified($file) < $cutoff) {
                Storage::disk('local')->delete($file);
            }
        }
    }

    private function authorizeManage(string $propertyId): void
    {
        $userId = session('auth.user_id');
        abort_unless($this->properties->userCanManageProperty($userId, $propertyId), 404);
    }

    /** Manage the property AND confirm the photo belongs to it, or 404. */
    private function authorizeOwnsPhoto(string $propertyId, string $photoId): void
    {
        $this->authorizeManage($propertyId);

        $belongs = \App\Models\Property\PropertyPhoto::on('property_read')
            ->where('id', $photoId)
            ->where('property_id', $propertyId)
            ->whereNull('deleted_at')
            ->exists();

        abort_unless($belongs, 404);
    }
}
