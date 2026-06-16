<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Services\Property\PropertyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Landowner front-end property photos (member portal) — the Photos tab of the
 * details hub. Upload, caption, set-primary, reorder and soft-delete, all scoped
 * through PropertyService::userCanManageProperty (the properties table has no RLS
 * policy); mutations on a single photo are additionally re-scoped to the property
 * so a foreign photo id 404s into a no-op. Redirects back so the active tab and
 * scroll position are preserved.
 */
class PropertyPhotoController extends Controller
{
    public function __construct(private readonly PropertyService $properties) {}

    public function store(Request $request, string $property): RedirectResponse
    {
        $this->authorizeManage($property);

        $data = $request->validate([
            'photos'     => 'required|array|max:20',
            'photos.*'   => 'image|max:15360', // 15 MB
            'caption'    => 'nullable|string|max:255',
            'import_exif' => 'boolean',
        ]);

        $uploaded = 0;
        foreach ($data['photos'] as $file) {
            try {
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

        $data = $request->validate(['caption' => 'nullable|string|max:255']);

        $this->properties->updatePhotoDetails($photo, $data['caption'] ?? null, []);

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
