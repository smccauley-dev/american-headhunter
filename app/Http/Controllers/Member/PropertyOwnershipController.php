<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Models\Property\PropertyOwnershipVerification;
use App\Services\Documents\DocumentService;
use App\Services\Property\PropertyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Landowner front-end proof-of-ownership (member portal) — the Ownership section of
 * the property form. A landowner uploads proof that they own or manage the parcel
 * (deed, county tax record, plat, management agreement, entity docs) and accepts a
 * penalty-of-perjury attestation; staff review it before the property may go live.
 *
 * Everything is scoped through PropertyService::userCanManageProperty (the
 * properties table and its children carry no RLS policy). Uploads mirror the
 * property-photos FilePond flow: each dropped file is temp-staged (tempStore) and
 * revertable (tempRevert), then committed as a batch on submit (store).
 */
class PropertyOwnershipController extends Controller
{
    public function __construct(
        private readonly PropertyService $properties,
        private readonly DocumentService $documents,
    ) {}

    /** FilePond process: stage one dropped proof file (image or PDF), return its token. */
    public function tempStore(Request $request, string $property): Response
    {
        $this->authorizeManage($property);

        $request->validate([
            'document' => 'required|file|mimes:jpg,jpeg,png,webp,heic,pdf|max:15360', // 15 MB
        ]);

        $this->pruneTemp($property);

        $path = $request->file('document')->store($this->tempDir($property), 'local');

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

    /** Submit the proof-of-ownership package for staff review. */
    public function store(Request $request, string $property): RedirectResponse
    {
        $this->authorizeManage($property);

        // Ownership verifies once and stays verified. The member UI hides the submit
        // form after approval; this rejects any direct POST so a verified property
        // can't be knocked back into review or have its proof silently replaced.
        if ($this->properties->hasApprovedOwnership($property)) {
            return back()->with('error', 'Ownership is already verified for this property — no further submission is needed.');
        }

        $data = $request->validate([
            'owner_type'             => ['required', Rule::in(array_keys(PropertyOwnershipVerification::OWNER_TYPES))],
            'entity_name'            => 'nullable|string|max:200',
            'tmp_files'              => 'required|array|min:1|max:10',
            'tmp_files.*'            => 'string',
            'certification_name'     => 'required|string|max:200',
            'certification_accepted' => 'accepted',
        ], [
            'tmp_files.required'              => 'Upload at least one proof document.',
            'tmp_files.min'                   => 'Upload at least one proof document.',
            'certification_accepted.accepted' => 'You must certify the documents under penalty of perjury before submitting.',
        ]);

        // A company / manager submission must name the entity or the owner represented.
        if (in_array($data['owner_type'], ['company', 'manager'], true) && trim((string) ($data['entity_name'] ?? '')) === '') {
            return back()->withErrors([
                'entity_name' => $data['owner_type'] === 'company'
                    ? 'Enter the company or entity name shown on the documents.'
                    : 'Enter the name of the owner you manage this property for.',
            ])->withInput();
        }

        $ownerUserId = session('auth.user_id');
        $documentIds = [];

        foreach ($data['tmp_files'] as $token) {
            $path = $this->tempDir($property) . '/' . basename($token);
            if (! Storage::disk('local')->exists($path)) {
                continue; // stale/foreign token — skip rather than fail the batch
            }
            try {
                $file = new UploadedFile(
                    Storage::disk('local')->path($path),
                    basename($path),
                    Storage::disk('local')->mimeType($path) ?: 'application/octet-stream',
                    null,
                    true,
                );
                $doc = $this->documents->storeUploadedFile($file, $ownerUserId, 'ownership_proof', unattached: true);
                $documentIds[] = $doc->id;
            } catch (\Throwable $e) {
                report($e);
            } finally {
                Storage::disk('local')->delete($path);
            }
        }

        if (empty($documentIds)) {
            return back()->with('error', 'Proof upload failed. Try again.')->withInput();
        }

        $this->properties->submitOwnershipVerification(
            $property,
            $ownerUserId,
            $data['owner_type'],
            $data['entity_name'] ?? null,
            $documentIds,
            trim($data['certification_name']),
        );

        return redirect()
            ->route('member.properties.edit', $property)
            ->with('success', 'Proof of ownership submitted for review.');
    }

    /** Serve one proof document (image or PDF) to a manager of the property. */
    public function serveDocument(string $property, string $documentId)
    {
        $this->authorizeManage($property);

        $belongs = PropertyOwnershipVerification::on('property_read')
            ->where('property_id', $property)
            ->whereNull('deleted_at')
            ->get(['document_ids'])
            ->contains(fn (PropertyOwnershipVerification $v) => in_array($documentId, (array) $v->document_ids, true));
        abort_unless($belongs, 404);

        $doc  = \App\Models\Documents\Document::on('documents')->findOrFail($documentId);
        $disk = Storage::disk(config('filesystems.defaults.documents', 'local'));
        abort_unless($disk->exists($doc->storage_key), 404);

        return $disk->response(
            $doc->storage_key,
            $doc->original_filename,
            ['Content-Type' => $doc->mime_type ?? 'application/octet-stream', 'Cache-Control' => 'private, max-age=3600'],
        );
    }

    // ── Helpers (mirror PropertyPhotoController temp staging) ────────────────────

    /** Per-property staging dir for in-progress proof uploads. */
    private function tempDir(string $propertyId): string
    {
        return 'pending-ownership-proof/' . basename($propertyId);
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

    /** Resolve a property the current user owns or actively manages, or 404. */
    private function authorizeManage(string $propertyId)
    {
        $userId = session('auth.user_id');
        abort_unless($this->properties->userCanManageProperty($userId, $propertyId), 404);

        return $this->properties->find($propertyId) ?? abort(404);
    }
}
