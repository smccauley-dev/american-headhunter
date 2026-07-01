<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Identity\UserResource;
use App\Models\Documents\Document;
use App\Models\Identity\UserProfile;
use App\Services\Documents\DocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Mobile profile API. Runs as the Sanctum member under ah_runtime, so every read
 * and write is RLS-scoped to the caller's own row (InjectDatabaseContext seeds
 * app.current_user_id from $request->user()).
 *
 * Scope note: this exposes the identity + hunting core that UserResource
 * round-trips (name, bio, home state, DOB, phone, hunting profile, notification
 * preferences). The richer web editor surfaces (gear, social links, per-section
 * visibility, and the veteran / first-responder verification flow) are
 * intentionally out of this slice — they are managed on the web portal.
 */
class ProfileController extends Controller
{
    public function __construct(private readonly DocumentService $documents) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(new UserResource($user->load('profile')));
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'display_name' => 'nullable|string|max:100',
            'bio' => 'nullable|string|max:1000',
            'state_code' => 'nullable|string|size:2',
            'zip_code' => 'nullable|string|max:10',
            'date_of_birth' => 'nullable|date',
            'gender' => 'nullable|in:male,female,nonbinary,prefer_not_to_say',
            'phone' => 'nullable|string|max:20',
            'hunting' => 'nullable|array',
            'hunting.species' => 'nullable|array',
            'hunting.terrain' => 'nullable|array',
            'hunting.style' => 'nullable|string|max:100',
            'hunting.seasons' => 'nullable|array',
            'hunting.years_hunting' => 'nullable|integer|min:0|max:80',
            'hunting.preferred_states' => 'nullable|array',
            'notification_preferences' => 'nullable|array',
        ]);

        // phone is the only editable field on the users table.
        $user->update(['phone' => $data['phone'] ?? $user->phone]);

        $profile = $user->profile ?? UserProfile::firstOrNew(['user_id' => $user->id]);
        $profile->fill([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'display_name' => $data['display_name'] ?? null,
            'bio' => $data['bio'] ?? null,
            'state_code' => $data['state_code'] ?? null,
            'zip_code' => $data['zip_code'] ?? null,
            'date_of_birth' => $data['date_of_birth'] ?? null,
            'gender' => $data['gender'] ?? null,
            'hunting_profile' => [
                'species' => $data['hunting']['species'] ?? [],
                'terrain' => $data['hunting']['terrain'] ?? [],
                'style' => $data['hunting']['style'] ?? null,
                'seasons' => $data['hunting']['seasons'] ?? [],
                'years_hunting' => isset($data['hunting']['years_hunting'])
                                          ? (int) $data['hunting']['years_hunting']
                                          : null,
                'preferred_states' => $data['hunting']['preferred_states'] ?? [],
            ],
        ]);

        if (array_key_exists('notification_preferences', $data)) {
            $profile->notification_preferences = $data['notification_preferences'];
        }

        $profile->save();

        return response()->json(new UserResource($user->fresh()->load('profile')));
    }

    /**
     * Instant avatar upload. Mirrors the web ProfileController::uploadAvatar
     * storage path (Garage-backed 'local' disk + a document row), returning JSON
     * for the mobile client instead of plain text.
     */
    public function uploadAvatar(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => 'required|image|max:4096|mimes:jpg,jpeg,png,webp',
        ]);

        $user = $request->user();
        $file = $request->file('avatar');
        $ext = strtolower($file->getClientOriginalExtension());
        $key = "avatars/{$user->id}.{$ext}";

        Storage::disk('local')->putFileAs('avatars', $file, "{$user->id}.{$ext}");

        $doc = $this->documents->register(
            ownerUserId: $user->id,
            documentType: 'avatar',
            originalFilename: $file->getClientOriginalName(),
            mimeType: $file->getMimeType() ?? 'image/jpeg',
            sizeBytes: $file->getSize(),
            storageBucket: 'local',
            storageKey: $key,
            storageProvider: 'garage',
        );

        $profile = $user->profile ?? UserProfile::firstOrNew(['user_id' => $user->id]);
        $profile->avatar_document_id = $doc->id;
        $profile->save();

        return response()->json(['avatar_document_id' => $doc->id], 201);
    }

    /** Stream the caller's own avatar. Token-auth analogue of the web serveAvatar. */
    public function serveAvatar(Request $request): StreamedResponse
    {
        $profile = $request->user()->profile;

        if (! $profile?->avatar_document_id) {
            abort(404);
        }

        $doc = Document::find($profile->avatar_document_id);
        if (! $doc || ! Storage::disk('local')->exists($doc->storage_key)) {
            abort(404);
        }

        return Storage::disk('local')->response($doc->storage_key, null, [
            'Content-Type' => $doc->mime_type ?? 'image/jpeg',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
