<?php

namespace App\Services\Identity;

use App\Models\Documents\Document;
use App\Models\Identity\ProfilePhoto;
use App\Services\Audit\AuditService;
use App\Support\PhotoTagVocabulary;
use Illuminate\Support\Facades\Storage;

/**
 * Metadata layer over a hunter's profile-gallery photos. The image bytes + base
 * file record live in DB 11 (documents, document_type='profile_photo'); this
 * service owns the DB 1 profile_photos row that carries the user-facing extras
 * (caption, description, controlled tags, location, order).
 *
 * No RLS on either table — ownership is enforced here and in the controller by
 * matching owner_user_id / user_id, exactly like avatars and user_profiles.
 */
class ProfilePhotoService
{
    /**
     * Harvest species_code (DB 5 vocabulary) → controlled photo tag. Codes with
     * no gallery-vocabulary equivalent (antelope, other) map to no tag.
     */
    private const HARVEST_SPECIES_TAGS = [
        'whitetail_deer' => 'whitetail',
        'mule_deer'      => 'mule_deer',
        'elk'            => 'elk',
        'turkey'         => 'turkey',
        'hog'            => 'hog',
        'bear'           => 'black_bear',
        'waterfowl'      => 'waterfowl',
        'dove'           => 'dove',
        'quail'          => 'quail',
        'pheasant'       => 'pheasant',
        'rabbit'         => 'small_game',
        'squirrel'       => 'small_game',
        'coyote'         => 'coyote',
    ];

    public function __construct(private readonly AuditService $audit) {}

    /**
     * Create the metadata row for a freshly-uploaded photo. EXIF GPS, if present
     * in the source image, is read into the exif_* columns only — it is never
     * applied to latitude/longitude until the hunter opts in.
     */
    public function createForUpload(string $userId, Document $document, ?string $localPath = null): ProfilePhoto
    {
        $exif = $localPath ? $this->extractExifGps($localPath) : null;

        $photo = ProfilePhoto::create([
            'user_id'        => $userId,
            'document_id'    => $document->id,
            'tags'           => [],
            'sort_order'     => $this->nextSortOrder($userId),
            'exif_latitude'  => $exif['lat'] ?? null,
            'exif_longitude' => $exif['lng'] ?? null,
        ]);

        $this->audit->log(
            eventType:     'profile_photo.uploaded',
            sourceDatabase: 'identity',
            tableName:     'profile_photos',
            recordId:      $photo->id,
            userId:        $userId,
            actionSummary: 'Profile gallery photo uploaded',
        );

        return $photo;
    }

    /**
     * Mirror a harvest field photo into the profile gallery. The document
     * already exists (DB 11, document_type='photo', queued for the virus scan) —
     * this only creates the gallery metadata row, auto-tagged with the species
     * and captioned from it.
     *
     * When the hunter chose to keep the photo's location data, the stored bytes
     * retain their EXIF GPS, so the row is flagged is_location_private: it must
     * never be publicly servable regardless of the gallery visibility setting
     * (SEC-061 / SEC-024). The coordinates are read into the exif_* columns for
     * the owner's own use only and are never logged.
     */
    public function createForHarvestPhoto(
        string $userId,
        Document $document,
        bool $keepLocation = false,
        ?string $speciesCode = null,
        ?string $exifSourcePath = null,
    ): ProfilePhoto {
        $tag  = self::HARVEST_SPECIES_TAGS[$speciesCode] ?? null;
        $exif = ($keepLocation && $exifSourcePath !== null) ? $this->extractExifGps($exifSourcePath) : null;

        $photo = ProfilePhoto::create([
            'user_id'        => $userId,
            'document_id'    => $document->id,
            'caption'        => $this->harvestCaption($speciesCode),
            'tags'           => PhotoTagVocabulary::sanitize($tag !== null ? [$tag] : []),
            'sort_order'     => $this->nextSortOrder($userId),
            'exif_latitude'  => $exif['lat'] ?? null,
            'exif_longitude' => $exif['lng'] ?? null,
            'is_location_private' => $keepLocation,
        ]);

        $this->audit->log(
            eventType:     'profile_photo.uploaded',
            sourceDatabase: 'identity',
            tableName:     'profile_photos',
            recordId:      $photo->id,
            userId:        $userId,
            actionSummary: 'Harvest field photo mirrored to profile gallery',
        );

        return $photo;
    }

    /**
     * Shaped gallery for the client, in display order. Each entry carries the
     * serve URL plus all editable metadata and a flag for whether unapplied EXIF
     * GPS is available to offer an opt-in prompt.
     *
     * @return list<array<string, mixed>>
     */
    public function listForUser(string $userId): array
    {
        return ProfilePhoto::where('user_id', $userId)
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get()
            ->map(fn (ProfilePhoto $p) => [
                'id'             => $p->document_id,
                'url'            => route('member.profile.photos.serve', $p->document_id),
                'caption'        => $p->caption,
                'description'    => $p->description,
                'tags'           => $p->tags ?? [],
                'latitude'       => $p->latitude,
                'longitude'      => $p->longitude,
                'location_name'  => $p->location_name,
                'has_exif_gps'   => $p->exif_latitude !== null && $p->exif_longitude !== null,
                'exif_latitude'  => $p->exif_latitude,
                'exif_longitude' => $p->exif_longitude,
                'is_location_private' => (bool) $p->is_location_private,
            ])
            ->values()
            ->all();
    }

    /**
     * Update editable metadata for one photo. Caption/description are trimmed to
     * null when blank; tags are filtered against the controlled vocabulary;
     * location is set only when both coordinates are supplied (or cleared when
     * both are null).
     *
     * @param  array<string, mixed>  $input
     */
    public function updateMeta(string $userId, string $documentId, array $input): ProfilePhoto
    {
        $photo = $this->ownedOrFail($userId, $documentId);

        if (array_key_exists('caption', $input)) {
            $photo->caption = $this->nullableString($input['caption'], 140);
        }
        if (array_key_exists('description', $input)) {
            $photo->description = $this->nullableString($input['description']);
        }
        if (array_key_exists('tags', $input)) {
            $photo->tags = PhotoTagVocabulary::sanitize((array) $input['tags']);
        }
        if (array_key_exists('latitude', $input) || array_key_exists('longitude', $input)) {
            $lat = $this->nullableCoord($input['latitude'] ?? null, 90);
            $lng = $this->nullableCoord($input['longitude'] ?? null, 180);
            $photo->latitude  = ($lat !== null && $lng !== null) ? $lat : null;
            $photo->longitude = ($lat !== null && $lng !== null) ? $lng : null;
        }
        if (array_key_exists('location_name', $input)) {
            $photo->location_name = $this->nullableString($input['location_name'], 160);
        }

        $photo->save();

        $this->audit->log(
            eventType:     'profile_photo.updated',
            sourceDatabase: 'identity',
            tableName:     'profile_photos',
            recordId:      $photo->id,
            userId:        $userId,
            actionSummary: 'Profile gallery photo metadata updated',
        );

        return $photo;
    }

    /**
     * Persist a new gallery order. Only the caller's own photos are reordered;
     * ids that aren't theirs are ignored. Photos omitted from the list keep their
     * existing order behind the supplied ones.
     *
     * @param  list<string>  $orderedDocumentIds
     */
    public function reorder(string $userId, array $orderedDocumentIds): void
    {
        $owned = ProfilePhoto::where('user_id', $userId)
            ->get()
            ->keyBy('document_id');

        $order = 0;
        foreach ($orderedDocumentIds as $documentId) {
            $photo = $owned->get($documentId);
            if ($photo === null) {
                continue;
            }
            if ($photo->sort_order !== $order) {
                $photo->sort_order = $order;
                $photo->save();
            }
            $order++;
        }

        $this->audit->log(
            eventType:     'profile_photo.reordered',
            sourceDatabase: 'identity',
            tableName:     'profile_photos',
            recordId:      $userId,
            userId:        $userId,
            actionSummary: 'Profile gallery reordered',
        );
    }

    /**
     * Soft-delete a photo: both the metadata row and the underlying document.
     */
    public function delete(string $userId, string $documentId): void
    {
        $photo = ProfilePhoto::where('user_id', $userId)
            ->where('document_id', $documentId)
            ->first();

        $document = Document::where('id', $documentId)
            ->where('owner_user_id', $userId)
            ->whereNull('deleted_at')
            ->first();

        if ($photo === null && $document === null) {
            abort(404);
        }

        $photo?->delete();
        $document?->delete();

        $this->audit->log(
            eventType:     'profile_photo.deleted',
            sourceDatabase: 'identity',
            tableName:     'profile_photos',
            recordId:      $photo?->id ?? $documentId,
            userId:        $userId,
            actionSummary: 'Profile gallery photo deleted',
        );
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function ownedOrFail(string $userId, string $documentId): ProfilePhoto
    {
        $photo = ProfilePhoto::where('user_id', $userId)
            ->where('document_id', $documentId)
            ->first();

        if ($photo === null) {
            abort(404);
        }

        return $photo;
    }

    private function nextSortOrder(string $userId): int
    {
        $max = ProfilePhoto::where('user_id', $userId)->max('sort_order');

        return $max === null ? 0 : (int) $max + 1;
    }

    private function harvestCaption(?string $speciesCode): string
    {
        if ($speciesCode === null || $speciesCode === 'other') {
            return 'Harvest';
        }

        return ucwords(str_replace('_', ' ', $speciesCode)) . ' harvest';
    }

    private function nullableString(mixed $value, ?int $max = null): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return $max !== null ? mb_substr($value, 0, $max) : $value;
    }

    private function nullableCoord(mixed $value, float $bound): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }
        $float = (float) $value;

        return abs($float) <= $bound ? $float : null;
    }

    /**
     * Read decimal GPS coordinates from an image's EXIF block, if any. Returns
     * null when the file has no GPS tags or EXIF support is unavailable (e.g.
     * PNG/WebP, which don't carry EXIF GPS).
     *
     * @return array{lat: float, lng: float}|null
     */
    private function extractExifGps(string $path): ?array
    {
        if (! function_exists('exif_read_data') || ! is_file($path)) {
            return null;
        }

        $exif = @exif_read_data($path);
        if ($exif === false || empty($exif['GPSLatitude']) || empty($exif['GPSLongitude'])) {
            return null;
        }

        $lat = $this->gpsToDecimal($exif['GPSLatitude'], $exif['GPSLatitudeRef'] ?? 'N');
        $lng = $this->gpsToDecimal($exif['GPSLongitude'], $exif['GPSLongitudeRef'] ?? 'E');

        if ($lat === null || $lng === null) {
            return null;
        }

        return ['lat' => $lat, 'lng' => $lng];
    }

    /**
     * Convert an EXIF degrees/minutes/seconds triple (each a "num/den" rational)
     * plus a hemisphere ref into a signed decimal degree.
     *
     * @param  array<int, string>  $dms
     */
    private function gpsToDecimal(array $dms, string $ref): ?float
    {
        if (count($dms) < 3) {
            return null;
        }

        $deg = $this->rational($dms[0]);
        $min = $this->rational($dms[1]);
        $sec = $this->rational($dms[2]);

        if ($deg === null || $min === null || $sec === null) {
            return null;
        }

        $decimal = $deg + ($min / 60) + ($sec / 3600);
        if (in_array(strtoupper($ref), ['S', 'W'], true)) {
            $decimal = -$decimal;
        }

        return round($decimal, 6);
    }

    private function rational(string $value): ?float
    {
        if (! str_contains($value, '/')) {
            return is_numeric($value) ? (float) $value : null;
        }

        [$num, $den] = explode('/', $value, 2);
        if (! is_numeric($num) || ! is_numeric($den) || (float) $den === 0.0) {
            return null;
        }

        return (float) $num / (float) $den;
    }
}
