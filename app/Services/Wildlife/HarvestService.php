<?php

namespace App\Services\Wildlife;

use App\Models\Documents\Document;
use App\Models\Wildlife\HarvestLog;
use App\Services\Audit\AuditService;
use App\Services\BaseService;
use App\Services\Documents\DocumentService;
use App\Services\Identity\ProfilePhotoService;
use App\Services\Property\GeospatialService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Harvest logging — the field-operation write path.
 *
 * Order of operations is deliberate and each step is a guard:
 *   1. standing (WildlifeAccess) — the only authz boundary on DB 5.
 *   2. offline dedup on local_record_id — a replayed submission returns the
 *      existing row and never touches the quota again.
 *   3. CWD gate — harvesting in a positive zone requires an acknowledgment before
 *      anything is written (422 with the zones so the client can prompt).
 *   4. atomic quota claim — 0 rows updated => quota full => 409 (audited).
 *   5. GPS to DB 13 — coordinates never land in DB 5.
 *   6. insert + CWD acks + audit.
 *
 * If the insert fails after the quota was claimed (e.g. the unique index rejects a
 * racing replay) the claim is released so the count stays honest.
 */
class HarvestService extends BaseService
{
    public function __construct(
        private readonly WildlifeAccess $access,
        private readonly QuotaService $quotas,
        private readonly CwdService $cwd,
        private readonly GeospatialService $geo,
        private readonly AuditService $audit,
        private readonly DocumentService $documents,
        private readonly ProfilePhotoService $profilePhotos,
    ) {}

    /**
     * Log a harvest against an active lease the user has standing on.
     *
     * @param  array<string,mixed>  $data  species_code, harvest_date, weapon_type
     *                                     (required); harvest_time, antler_score, weight_lbs, age_estimate, notes,
     *                                     is_public, field_photos[]; latitude, longitude, gps_accuracy_m;
     *                                     local_record_id; cwd_acknowledged.
     */
    public function log(string $userId, string $leaseId, array $data): HarvestLog
    {
        $lease = $this->access->assertLeaseStanding($userId, $leaseId);
        $propertyId = $lease->property_id;

        // Offline dedup: a replayed record returns the original, no quota re-claim.
        $localId = $data['local_record_id'] ?? null;
        if ($localId !== null) {
            $existing = HarvestLog::on('wildlife')
                ->where('user_id', $userId)
                ->where('local_record_id', $localId)
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        $speciesCode = $data['species_code'];
        $seasonYear = Carbon::parse($data['harvest_date'])->year;

        $lat = isset($data['latitude']) ? (float) $data['latitude'] : null;
        $lng = isset($data['longitude']) ? (float) $data['longitude'] : null;
        $hasPoint = $lat !== null && $lng !== null;

        // CWD compliance gate — before any side effect so a 422 is safe to retry.
        $requiredZones = $hasPoint
            ? $this->cwd->zonesRequiringAcknowledgment($lng, $lat)
            : collect();

        if ($requiredZones->isNotEmpty() && ! ($data['cwd_acknowledged'] ?? false)) {
            abort(422, 'CWD acknowledgment required: '.$requiredZones->pluck('zone_name')->join(', '));
        }

        // Atomic quota claim — reject a full quota before writing anything.
        if (! $this->quotas->tryConsume($propertyId, $leaseId, $speciesCode, $seasonYear)) {
            $this->audit->log(
                eventType: 'harvest.quota_exhausted',
                sourceDatabase: 'wildlife',
                tableName: 'harvest_quotas',
                recordId: $leaseId,
                userId: $userId,
                actionSummary: "Rejected harvest of {$speciesCode}: season {$seasonYear} quota exhausted",
            );

            abort(409, 'Harvest quota for this species is already full for the season.');
        }

        $harvestId = (string) Str::uuid();

        try {
            $geoId = $hasPoint
                ? $this->geo->storeHarvestLocation($harvestId, $lng, $lat, $data['gps_accuracy_m'] ?? null)
                : null;

            $harvest = HarvestLog::create([
                'id' => $harvestId,
                'lease_id' => $leaseId,
                'user_id' => $userId,
                'property_id' => $propertyId,
                'species_code' => $speciesCode,
                'harvest_date' => $data['harvest_date'],
                'harvest_time' => $data['harvest_time'] ?? null,
                'location_geospatial_id' => $geoId,
                'weapon_type' => $data['weapon_type'],
                'antler_score' => $data['antler_score'] ?? null,
                'weight_lbs' => $data['weight_lbs'] ?? null,
                'age_estimate' => $data['age_estimate'] ?? null,
                'field_photos' => $data['field_photos'] ?? [],
                'notes' => $data['notes'] ?? null,
                'is_public' => $data['is_public'] ?? false,
                'local_record_id' => $localId,
            ]);
        } catch (\Throwable $e) {
            // A racing replay lost the unique-index race, or the insert failed —
            // return the claimed tag so the count is not inflated.
            $this->quotas->release($propertyId, $leaseId, $speciesCode, $seasonYear);

            // If the loser of a replay race, hand back the winning row instead of erroring.
            if ($localId !== null) {
                $winner = HarvestLog::on('wildlife')
                    ->where('user_id', $userId)
                    ->where('local_record_id', $localId)
                    ->first();

                if ($winner) {
                    return $winner;
                }
            }

            throw $e;
        }

        foreach ($requiredZones as $zone) {
            $this->cwd->acknowledge($userId, $harvestId, $zone->id);
        }

        $this->audit->log(
            eventType: 'harvest.logged',
            sourceDatabase: 'wildlife',
            tableName: 'harvest_logs',
            recordId: $harvestId,
            userId: $userId,
            actionSummary: "Logged harvest of {$speciesCode} on lease ".strtoupper(substr($leaseId, 0, 8)),
        );

        // Field photos are attached after the fact via attachFieldPhoto(): each is
        // EXIF-stripped (unless the hunter opts to keep location data — SEC-061)
        // and handed to DocumentService, which virus-scans it (the existing
        // ScanDocumentForViruses job) before it becomes servable. A fresh harvest
        // is created with no photos.

        return $harvest;
    }

    /**
     * Full edit of the caller's OWN harvest — co-hunters and managers may read a
     * record, but only its author may change it (403; a stranger still 404s via
     * findForUser, never disclosing existence).
     *
     * Mirrors log()'s guard ordering so a rejection leaves the record untouched:
     *   1. owner check.
     *   2. CWD gate for a NEW location — before any side effect (422, safe retry).
     *   3. quota bucket move — species or season-year changes release the old tag
     *      only AFTER the new one is atomically claimed; a full new bucket is a
     *      409 with the old tag intact.
     *   4. location: harvest_locations (DB 13) is immutable, so a changed spot
     *      writes a NEW point and repoints location_geospatial_id (the old row
     *      stays, by design). clear_location detaches the reference.
     *   5. persist + CWD acks for the new point + audit.
     *
     * @param  array<string,mixed>  $data  any of: species_code, harvest_date,
     *                                     harvest_time, weapon_type, antler_score, weight_lbs, age_estimate, notes,
     *                                     is_public; latitude+longitude (+gps_accuracy_m) for a new point;
     *                                     clear_location; cwd_acknowledged.
     */
    public function update(string $userId, string $harvestId, array $data): HarvestLog
    {
        $harvest = $this->findForUser($userId, $harvestId);
        abort_unless($harvest->user_id === $userId, 403, 'Only the hunter who logged this harvest can edit it.');

        $propertyId = $harvest->property_id;
        $leaseId = $harvest->lease_id;

        $oldSpecies = $harvest->species_code;
        $oldYear = $harvest->harvest_date->year;
        $newSpecies = $data['species_code'] ?? $oldSpecies;
        $newYear = isset($data['harvest_date']) ? Carbon::parse($data['harvest_date'])->year : $oldYear;

        $lat = isset($data['latitude']) ? (float) $data['latitude'] : null;
        $lng = isset($data['longitude']) ? (float) $data['longitude'] : null;
        $hasNewPoint = $lat !== null && $lng !== null;

        // CWD compliance gate for the new spot — before any side effect.
        $requiredZones = $hasNewPoint
            ? $this->cwd->zonesRequiringAcknowledgment($lng, $lat)
            : collect();

        if ($requiredZones->isNotEmpty() && ! ($data['cwd_acknowledged'] ?? false)) {
            abort(422, 'CWD acknowledgment required: '.$requiredZones->pluck('zone_name')->join(', '));
        }

        // Quota bucket move — claim the new tag before releasing the old one so a
        // rejected edit never loses the original claim.
        $bucketChanged = $newSpecies !== $oldSpecies || $newYear !== $oldYear;
        if ($bucketChanged) {
            if (! $this->quotas->tryConsume($propertyId, $leaseId, $newSpecies, $newYear)) {
                $this->audit->log(
                    eventType: 'harvest.quota_exhausted',
                    sourceDatabase: 'wildlife',
                    tableName: 'harvest_quotas',
                    recordId: $leaseId,
                    userId: $userId,
                    actionSummary: "Rejected harvest edit to {$newSpecies}: season {$newYear} quota exhausted",
                );

                abort(409, 'Harvest quota for that species is already full for the season.');
            }
            $this->quotas->release($propertyId, $leaseId, $oldSpecies, $oldYear);
        }

        try {
            if ($hasNewPoint) {
                $harvest->location_geospatial_id = $this->geo->storeHarvestLocation(
                    $harvest->id, $lng, $lat, $data['gps_accuracy_m'] ?? null,
                );
            } elseif ($data['clear_location'] ?? false) {
                $harvest->location_geospatial_id = null;
            }

            foreach (['species_code', 'harvest_date', 'harvest_time', 'weapon_type', 'antler_score', 'weight_lbs', 'age_estimate', 'notes', 'is_public'] as $field) {
                if (array_key_exists($field, $data)) {
                    $harvest->{$field} = $data[$field];
                }
            }

            $harvest->save();
        } catch (\Throwable $e) {
            // Reverse the bucket move so the counts stay honest (best-effort: the
            // old bucket may have been re-claimed by someone else meanwhile).
            if ($bucketChanged) {
                $this->quotas->release($propertyId, $leaseId, $newSpecies, $newYear);
                $this->quotas->tryConsume($propertyId, $leaseId, $oldSpecies, $oldYear);
            }

            throw $e;
        }

        foreach ($requiredZones as $zone) {
            $this->cwd->acknowledge($userId, $harvest->id, $zone->id);
        }

        $this->audit->log(
            eventType: 'harvest.updated',
            sourceDatabase: 'wildlife',
            tableName: 'harvest_logs',
            recordId: $harvest->id,
            userId: $userId,
            actionSummary: "Updated harvest of {$newSpecies} on lease ".strtoupper(substr($leaseId, 0, 8)),
        );

        return $harvest;
    }

    /**
     * Soft-delete the caller's OWN harvest and release its quota tag so the
     * season count stays honest. The DB 13 location point is immutable and stays;
     * attached photos keep their documents and gallery entries.
     */
    public function delete(string $userId, string $harvestId): void
    {
        $harvest = $this->findForUser($userId, $harvestId);
        abort_unless($harvest->user_id === $userId, 403, 'Only the hunter who logged this harvest can delete it.');

        $this->quotas->release($harvest->property_id, $harvest->lease_id, $harvest->species_code, $harvest->harvest_date->year);

        $harvest->delete();

        $this->audit->log(
            eventType: 'harvest.deleted',
            sourceDatabase: 'wildlife',
            tableName: 'harvest_logs',
            recordId: $harvest->id,
            userId: $userId,
            actionSummary: "Deleted harvest of {$harvest->species_code} on lease ".strtoupper(substr($harvest->lease_id, 0, 8)),
        );
    }

    /**
     * Detach one field photo from the caller's OWN harvest and soft-delete its
     * document + profile-gallery mirror (both owner-scoped in ProfilePhotoService).
     */
    public function removeFieldPhoto(string $userId, string $harvestId, string $documentId): void
    {
        $harvest = $this->findForUser($userId, $harvestId);
        abort_unless($harvest->user_id === $userId, 403, 'Only the hunter who logged this harvest can edit it.');
        abort_unless(in_array($documentId, $harvest->field_photos ?? [], true), 404);

        $harvest->field_photos = array_values(array_filter(
            $harvest->field_photos ?? [],
            fn (string $id) => $id !== $documentId,
        ));
        $harvest->save();

        $this->profilePhotos->delete($userId, $documentId);

        $this->audit->log(
            eventType: 'harvest.photo_removed',
            sourceDatabase: 'wildlife',
            tableName: 'harvest_logs',
            recordId: $harvestId,
            userId: $userId,
            actionSummary: 'Removed a field photo from harvest '.strtoupper(substr($harvestId, 0, 8)),
        );
    }

    /**
     * The caller's own harvest logs, newest first. Standing-scoped: only rows the
     * caller authored are returned (no cross-tenant read).
     *
     * @return Collection<int,HarvestLog>
     */
    public function listForUser(string $userId, int $limit = 100): Collection
    {
        return HarvestLog::on('wildlife')
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->orderByDesc('harvest_date')
            ->limit($limit)
            ->get();
    }

    /**
     * A single harvest the user is allowed to read (own record, standing on the
     * lease, or manages the property). 404 otherwise — never disclose existence.
     */
    public function findForUser(string $userId, string $harvestId): HarvestLog
    {
        $harvest = HarvestLog::on('wildlife')
            ->where('id', $harvestId)
            ->whereNull('deleted_at')
            ->firstOrFail();

        $this->access->assertRecordAccess($userId, $harvest->user_id, $harvest->lease_id, $harvest->property_id);

        return $harvest;
    }

    /**
     * Attach a field photo to a harvest the caller may access.
     *
     * The photo is untrusted input and is handled defensively:
     *   1. standing is re-enforced (findForUser 404s an unrelated caller).
     *   2. by default the image is re-encoded through GD, which drops all embedded
     *      metadata — critically the EXIF GPS tag, which would otherwise leak the
     *      precise harvest location the platform deliberately keeps only in
     *      DB 13 (SEC-024). When the hunter explicitly opts to keep the photo's
     *      location data ($keepLocation), the original bytes are stored instead —
     *      still fully decoded/validated as an image — and the mirrored gallery
     *      row is flagged is_location_private so it can never be served publicly
     *      (SEC-061).
     *   3. the bytes go to DocumentService, which stores them in 'processing' and
     *      queues the existing ScanDocumentForViruses job. The document id is
     *      appended to field_photos, but the photo is not servable until the scan
     *      marks it 'ready'.
     *   4. the photo is mirrored into the member's profile Photos gallery (DB 1),
     *      auto-tagged with the species. The mirror is best-effort: a gallery
     *      failure never voids the harvest attachment.
     */
    public function attachFieldPhoto(string $userId, string $harvestId, UploadedFile $file, bool $keepLocation = false): Document
    {
        $harvest = $this->findForUser($userId, $harvestId);

        [$bytes, $mimeType, $filename] = $keepLocation
            ? $this->validateOriginalImage($file)
            : $this->sanitizeImage($file);

        $document = $this->documents->storeRawBytes($bytes, $userId, 'photo', $filename, $mimeType);

        rescue(fn () => $this->profilePhotos->createForHarvestPhoto(
            $userId,
            $document,
            $keepLocation,
            $harvest->species_code,
            $keepLocation ? ($file->getRealPath() ?: null) : null,
        ));

        $harvest->field_photos = array_values(array_merge($harvest->field_photos ?? [], [$document->id]));
        $harvest->save();

        $this->audit->log(
            eventType: 'harvest.photo_attached',
            sourceDatabase: 'wildlife',
            tableName: 'harvest_logs',
            recordId: $harvestId,
            userId: $userId,
            actionSummary: 'Attached a field photo to harvest '.strtoupper(substr($harvestId, 0, 8)),
        );

        return $document;
    }

    /**
     * Re-encode an uploaded image through GD to strip all metadata (notably the
     * EXIF GPS tag — SEC-024), preserving the original format. Returns
     * [bytes, mimeType, filename].
     *
     * @return array{0:string,1:string,2:string}
     */
    private function sanitizeImage(UploadedFile $file): array
    {
        $raw = (string) file_get_contents($file->getRealPath());
        $image = @imagecreatefromstring($raw);

        if ($image === false) {
            abort(422, 'The photo could not be read as a valid image.');
        }

        $type = getimagesizefromstring($raw)[2] ?? IMAGETYPE_JPEG;
        $base = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) ?: 'harvest-photo';

        ob_start();
        switch ($type) {
            case IMAGETYPE_PNG:
                imagesavealpha($image, true);
                imagepng($image);
                $mimeType = 'image/png';
                $ext = 'png';
                break;
            case IMAGETYPE_WEBP:
                imagewebp($image);
                $mimeType = 'image/webp';
                $ext = 'webp';
                break;
            default:
                imagejpeg($image, null, 90);
                $mimeType = 'image/jpeg';
                $ext = 'jpg';
        }
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return [$bytes, $mimeType, "{$base}.{$ext}"];
    }

    /**
     * Validate an upload as a real, fully-decodable image WITHOUT re-encoding —
     * used only when the hunter explicitly opted to keep the photo's location
     * data, so the original bytes (EXIF GPS included) are stored as-is. Only the
     * three formats the validators accept are allowed, the bytes must decode
     * through GD (rejects polyglot/corrupt files), and the file is still
     * virus-scanned before it becomes servable. Returns [bytes, mimeType, filename].
     *
     * @return array{0:string,1:string,2:string}
     */
    private function validateOriginalImage(UploadedFile $file): array
    {
        $raw = (string) file_get_contents($file->getRealPath());

        if (@imagecreatefromstring($raw) === false) {
            abort(422, 'The photo could not be read as a valid image.');
        }

        $allowed = [
            IMAGETYPE_JPEG => ['image/jpeg', 'jpg'],
            IMAGETYPE_PNG => ['image/png', 'png'],
            IMAGETYPE_WEBP => ['image/webp', 'webp'],
        ];
        $type = getimagesizefromstring($raw)[2] ?? null;

        if (! isset($allowed[$type])) {
            abort(422, 'Only JPEG, PNG, or WebP photos are supported.');
        }

        [$mimeType, $ext] = $allowed[$type];
        $base = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) ?: 'harvest-photo';

        return [$raw, $mimeType, "{$base}.{$ext}"];
    }
}
