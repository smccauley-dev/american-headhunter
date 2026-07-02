<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Models\Documents\Document;
use App\Models\Identity\ProfilePhoto;
use App\Models\Wildlife\FishingHarvestLog;
use App\Models\Wildlife\HarvestLog;
use App\Models\Wildlife\HarvestQuota;
use App\Models\Wildlife\WildlifeSighting;
use App\Services\Lease\LeaseService;
use App\Services\Property\PropertyService;
use App\Services\Wildlife\FishingHarvestService;
use App\Services\Wildlife\HarvestMapService;
use App\Services\Wildlife\HarvestService;
use App\Services\Wildlife\QuotaService;
use App\Services\Wildlife\SightingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Member portal wildlife pages — the web sibling of the mobile wildlife API
 * (Phase 6.3a). Runs as the member (ah_runtime), but DB 5 has NO RLS: the
 * WildlifeAccess standing check inside each service is the entire authorization
 * boundary, re-enforced on every write. Reads are already caller-scoped by the
 * service (`listForUser`), never by the database.
 *
 * One web-only concern the API does not have: the log services signal a full
 * quota with `abort(409)` and a missing CWD acknowledgment with `abort(422)`.
 * Inertia reserves HTTP 409 for its own asset-version reload, so those statuses
 * must never reach the Inertia client — `harvestStore` catches them and turns
 * them into a flash error / a validation error that reveals the CWD ack prompt.
 *
 * Offline sync (6.3d): the same store endpoints serve the offline write queue.
 * When the request wants JSON (the queue flush sets `Accept: application/json`)
 * the store returns a plain JSON result with a real status code — 201 fresh /
 * 200 idempotent replay (dedup on the client-minted `local_record_id`), and the
 * 409/422/403 bubble as genuine statuses the flush can act on — instead of the
 * Inertia redirect it gives a browser form post.
 */
class WildlifeController extends Controller
{
    /** Harvestable species — mirrors the harvest_logs CHECK. code => label. */
    private const SPECIES = [
        'whitetail_deer' => 'Whitetail Deer',
        'mule_deer' => 'Mule Deer',
        'turkey' => 'Turkey',
        'waterfowl' => 'Waterfowl',
        'dove' => 'Dove',
        'hog' => 'Hog',
        'elk' => 'Elk',
        'bear' => 'Bear',
        'antelope' => 'Antelope',
        'pheasant' => 'Pheasant',
        'quail' => 'Quail',
        'rabbit' => 'Rabbit',
        'squirrel' => 'Squirrel',
        'coyote' => 'Coyote',
        'other' => 'Other',
    ];

    /** Weapon types — mirrors the harvest_logs CHECK. code => label. */
    private const WEAPONS = [
        'bow' => 'Bow',
        'rifle' => 'Rifle',
        'shotgun' => 'Shotgun',
        'muzzleloader' => 'Muzzleloader',
        'pistol' => 'Pistol',
        'other' => 'Other',
    ];

    /** Sighting species — the harvest set plus 'unknown' (wildlife_sightings CHECK). */
    private const SIGHTING_SPECIES = self::SPECIES + ['unknown' => 'Unknown'];

    /** Fish species — mirrors the fishing_harvest_logs CHECK. code => label. */
    private const FISH_SPECIES = [
        'largemouth_bass' => 'Largemouth Bass',
        'smallmouth_bass' => 'Smallmouth Bass',
        'crappie' => 'Crappie',
        'bluegill' => 'Bluegill',
        'catfish' => 'Catfish',
        'trout' => 'Trout',
        'walleye' => 'Walleye',
        'pike' => 'Pike',
        'perch' => 'Perch',
        'carp' => 'Carp',
        'striped_bass' => 'Striped Bass',
        'other' => 'Other',
    ];

    /** The member's own harvest log, newest first. */
    public function harvestIndex(Request $request, HarvestService $harvests, PropertyService $properties): InertiaResponse
    {
        $userId = session('auth.user_id');

        $logs = $harvests->listForUser($userId);
        $titles = $this->propertyTitles($logs->pluck('property_id')->all(), $properties);
        $readyPhotos = $this->readyPhotoIds($logs->flatMap(fn (HarvestLog $h) => $h->field_photos ?? [])->all());

        $rows = $logs->map(fn (HarvestLog $h) => [
            'id' => $h->id,
            'species' => self::SPECIES[$h->species_code] ?? $h->species_code,
            'weapon' => self::WEAPONS[$h->weapon_type] ?? $h->weapon_type,
            'harvest_date' => $h->harvest_date?->format('M j, Y'),
            'property_title' => $titles[$h->property_id] ?? 'Property',
            'antler_score' => $h->antler_score,
            'is_public' => (bool) $h->is_public,
            'photo_urls' => array_values(array_map(
                fn (string $id) => route('member.profile.photos.serve', $id),
                array_filter($h->field_photos ?? [], fn (string $id) => isset($readyPhotos[$id])),
            )),
            'edit_url' => route('member.harvest.edit', $h->id),
            'destroy_url' => route('member.harvest.destroy', $h->id),
        ])->all();

        return Inertia::render('Member/Harvest/Index', [
            'harvests' => $rows,
            'new_url' => route('member.harvest.new'),
            'quota_url' => route('member.quota'),
        ]);
    }

    /** The log-a-harvest form: the member's active leases + the species/weapon vocab. */
    public function harvestNew(Request $request, LeaseService $leases, PropertyService $properties): InertiaResponse
    {
        $userId = session('auth.user_id');
        $active = $leases->getActiveLeasesForLessee($userId);
        $titles = $this->propertyTitles($active->pluck('property_id')->all(), $properties);

        $leaseOptions = $active->map(fn ($lease) => [
            'id' => $lease->id,
            'property_title' => $titles[$lease->property_id] ?? 'Property',
            'end_date' => $lease->end_date?->format('M j, Y'),
        ])->values()->all();

        return Inertia::render('Member/Harvest/New', [
            'leases' => $leaseOptions,
            'species' => $this->options(self::SPECIES),
            'weapons' => $this->options(self::WEAPONS),
            'store_url' => route('member.harvest.store'),
            'index_url' => route('member.harvest.index'),
        ]);
    }

    /** Log a harvest against a lease the member has standing on. */
    public function harvestStore(Request $request, HarvestService $harvests): RedirectResponse|JsonResponse
    {
        $userId = session('auth.user_id');

        $data = $request->validate([
            'lease_id' => ['required', 'uuid'],
            'species_code' => ['required', Rule::in(array_keys(self::SPECIES))],
            'weapon_type' => ['required', Rule::in(array_keys(self::WEAPONS))],
            'harvest_date' => ['required', 'date', 'before_or_equal:today'],
            'harvest_time' => ['nullable', 'date_format:H:i'],
            'antler_score' => ['nullable', 'numeric', 'min:0'],
            'weight_lbs' => ['nullable', 'numeric', 'min:0'],
            'age_estimate' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_public' => ['nullable', 'boolean'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'gps_accuracy_m' => ['nullable', 'numeric', 'min:0'],
            'hide_location_from_members' => ['nullable', 'boolean'],
            'cwd_acknowledged' => ['nullable', 'boolean'],
            'local_record_id' => ['nullable', 'uuid'],
            'photos' => ['nullable', 'array', 'max:6'],
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'keep_photo_location' => ['nullable', 'boolean'],
        ]);

        try {
            $harvest = $harvests->log($userId, $data['lease_id'], $data);
        } catch (HttpException $e) {
            // The offline-sync flush wants the real status code (409 quota / 422 CWD
            // / 403 standing) so it can decide to drop, prompt, or stop retrying.
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
            }

            return match ($e->getStatusCode()) {
                // Full quota — a plain conflict the member can't resolve here.
                409 => back()->withInput()->with('error', $e->getMessage()),
                // CWD zone requires acknowledgment before the harvest is accepted —
                // surface as a field error so the form reveals the ack checkbox and
                // the member can re-submit. (422 must not reach Inertia as a status.)
                422 => back()->withInput()->withErrors(['cwd_acknowledged' => $e->getMessage()]),
                // No standing on the lease — a genuine deny.
                default => throw $e,
            };
        }

        // Photos ride the online browser post only (the offline queue cannot hold
        // files), and attach only to a fresh insert — a dedup replay of the same
        // local_record_id must not re-attach duplicates. Each photo is EXIF-
        // stripped unless the member opted to keep location data (SEC-061), then
        // virus-scanned before it is servable, and mirrored to the profile gallery.
        if ($harvest->wasRecentlyCreated) {
            $keepLocation = (bool) ($data['keep_photo_location'] ?? false);
            foreach ($request->file('photos', []) as $photo) {
                $harvests->attachFieldPhoto($userId, $harvest->id, $photo, $keepLocation);
            }
        }

        if ($request->expectsJson()) {
            return response()->json(['id' => $harvest->id], $harvest->wasRecentlyCreated ? 201 : 200);
        }

        return redirect()->route('member.harvest.index')->with('success', 'Harvest logged.');
    }

    /**
     * The edit form for one of the member's OWN harvests. Renders the same page
     * component as "new" with a `harvest` prop; the lease is fixed (a harvest
     * never moves between leases).
     */
    public function harvestEdit(Request $request, string $harvest, HarvestService $harvests, PropertyService $properties): InertiaResponse
    {
        $userId = session('auth.user_id');

        $log = $harvests->findForUser($userId, $harvest);
        abort_unless($log->user_id === $userId, 403);

        $titles = $this->propertyTitles([$log->property_id], $properties);
        $readyPhotos = $this->readyPhotoIds($log->field_photos ?? []);

        return Inertia::render('Member/Harvest/New', [
            'leases' => [[
                'id' => $log->lease_id,
                'property_title' => $titles[$log->property_id] ?? 'Property',
                'end_date' => null,
            ]],
            'species' => $this->options(self::SPECIES),
            'weapons' => $this->options(self::WEAPONS),
            'store_url' => route('member.harvest.store'),
            'index_url' => route('member.harvest.index'),
            'harvest' => [
                'id' => $log->id,
                'species_code' => $log->species_code,
                'weapon_type' => $log->weapon_type,
                'harvest_date' => $log->harvest_date?->toDateString(),
                'harvest_time' => $log->harvest_time ? substr($log->harvest_time, 0, 5) : '',
                'antler_score' => $log->antler_score,
                'weight_lbs' => $log->weight_lbs,
                'age_estimate' => $log->age_estimate,
                'notes' => $log->notes,
                'is_public' => (bool) $log->is_public,
                'hide_location_from_members' => (bool) $log->hide_location_from_members,
                'has_location' => $log->location_geospatial_id !== null,
                'photos' => array_values(array_map(fn (string $id) => [
                    'id' => $id,
                    'url' => isset($readyPhotos[$id]) ? route('member.profile.photos.serve', $id) : null,
                ], $log->field_photos ?? [])),
                'update_url' => route('member.harvest.update', $log->id),
                'destroy_url' => route('member.harvest.destroy', $log->id),
            ],
        ]);
    }

    /**
     * Full edit of one of the member's OWN harvests. The service re-runs the
     * quota accounting (species/season change moves the tag atomically) and the
     * CWD gate (when a new location is captured); the same 409/422 → flash/field
     * translation as the store applies. Photo changes ride the same request:
     * removals detach + soft-delete, additions go through attachFieldPhoto.
     */
    public function harvestUpdate(Request $request, string $harvest, HarvestService $harvests): RedirectResponse
    {
        $userId = session('auth.user_id');

        $data = $request->validate([
            'species_code' => ['required', Rule::in(array_keys(self::SPECIES))],
            'weapon_type' => ['required', Rule::in(array_keys(self::WEAPONS))],
            'harvest_date' => ['required', 'date', 'before_or_equal:today'],
            'harvest_time' => ['nullable', 'date_format:H:i'],
            'antler_score' => ['nullable', 'numeric', 'min:0'],
            'weight_lbs' => ['nullable', 'numeric', 'min:0'],
            'age_estimate' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_public' => ['nullable', 'boolean'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'gps_accuracy_m' => ['nullable', 'numeric', 'min:0'],
            'clear_location' => ['nullable', 'boolean'],
            'hide_location_from_members' => ['nullable', 'boolean'],
            'cwd_acknowledged' => ['nullable', 'boolean'],
            'photos' => ['nullable', 'array', 'max:6'],
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'keep_photo_location' => ['nullable', 'boolean'],
            'remove_photo_ids' => ['nullable', 'array'],
            'remove_photo_ids.*' => ['uuid'],
        ]);

        try {
            $harvests->update($userId, $harvest, $data);
        } catch (HttpException $e) {
            return match ($e->getStatusCode()) {
                // Target species/season quota is full — the old tag is untouched.
                409 => back()->withInput()->with('error', $e->getMessage()),
                // The new location sits in a CWD zone — reveal the ack checkbox.
                422 => back()->withInput()->withErrors(['cwd_acknowledged' => $e->getMessage()]),
                default => throw $e,
            };
        }

        foreach ($data['remove_photo_ids'] ?? [] as $documentId) {
            $harvests->removeFieldPhoto($userId, $harvest, $documentId);
        }

        $keepLocation = (bool) ($data['keep_photo_location'] ?? false);
        foreach ($request->file('photos', []) as $photo) {
            $harvests->attachFieldPhoto($userId, $harvest, $photo, $keepLocation);
        }

        return redirect()->route('member.harvest.index')->with('success', 'Harvest updated.');
    }

    /** Soft-delete one of the member's OWN harvests; its quota tag is released. */
    public function harvestDestroy(Request $request, string $harvest, HarvestService $harvests): RedirectResponse
    {
        $harvests->delete(session('auth.user_id'), $harvest);

        return redirect()->route('member.harvest.index')->with('success', 'Harvest deleted.');
    }

    /**
     * Serve a harvest field photo for the GPS-map popup. Unlike the owner-only
     * profile serve, this admits co-hunters — gated hard:
     *   - the document must be a scan-cleared harvest field photo (404 otherwise;
     *     existence is never disclosed);
     *   - a non-owner needs past/present standing on the property (or manages it);
     *   - a non-owner never gets a photo whose harvest hides its spot, nor a file
     *     that retains location metadata (SEC-061 — kept EXIF GPS would hand them
     *     the exact coordinates).
     */
    public function harvestPhoto(string $document, HarvestMapService $maps): StreamedResponse
    {
        $userId = session('auth.user_id');

        $doc = Document::where('id', $document)
            ->whereNull('deleted_at')
            ->where('status', 'ready')
            ->firstOrFail();

        $harvest = HarvestLog::on('wildlife')
            ->whereRaw('field_photos @> ?::jsonb', [json_encode([$document])])
            ->whereNull('deleted_at')
            ->first();
        abort_unless($harvest !== null, 404);

        if ($harvest->user_id !== $userId) {
            abort_unless($maps->canView($userId, $harvest->property_id), 404);
            abort_if((bool) $harvest->hide_location_from_members, 404);

            $retainsLocation = ProfilePhoto::where('document_id', $document)
                ->where('is_location_private', true)
                ->exists();
            abort_if($retainsLocation, 404);
        }

        abort_unless(Storage::disk('local')->exists($doc->storage_key), 404);

        return Storage::disk('local')->response($doc->storage_key, null, [
            'Content-Type' => $doc->mime_type ?? 'image/jpeg',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Remaining tags per species, grouped by the member's active leases. */
    public function quotaIndex(Request $request, LeaseService $leases, QuotaService $quotas, PropertyService $properties): InertiaResponse
    {
        $userId = session('auth.user_id');
        $year = (int) $request->query('year', (string) now()->year);
        $active = $leases->getActiveLeasesForLessee($userId);
        $titles = $this->propertyTitles($active->pluck('property_id')->all(), $properties);

        $groups = $active->map(fn ($lease) => [
            'lease_id' => $lease->id,
            'property_title' => $titles[$lease->property_id] ?? 'Property',
            'quotas' => $quotas->listForLease($lease->property_id, $lease->id, $year)
                ->map(fn (HarvestQuota $q) => [
                    'species' => self::SPECIES[$q->species_code] ?? $q->species_code,
                    'max_harvest' => (int) $q->max_harvest,
                    'current_harvest' => (int) $q->current_harvest,
                    'remaining' => $q->remaining(),
                    'scope' => $q->lease_id !== null ? 'lease' : 'property',
                ])->values()->all(),
        ])->values()->all();

        return Inertia::render('Member/Quota', [
            'season_year' => $year,
            'leases' => $groups,
            'harvest_url' => route('member.harvest.index'),
        ]);
    }

    // ── Sightings ─────────────────────────────────────────────────────────────

    /** The member's own wildlife sightings, newest first. */
    public function sightingIndex(Request $request, SightingService $sightings, PropertyService $properties): InertiaResponse
    {
        $userId = session('auth.user_id');

        $logs = $sightings->listForUser($userId);
        $titles = $this->propertyTitles($logs->pluck('property_id')->all(), $properties);

        $rows = $logs->map(fn (WildlifeSighting $s) => [
            'id' => $s->id,
            'species' => self::SIGHTING_SPECIES[$s->species_code] ?? $s->species_code,
            'count' => (int) $s->count,
            'sighting_date' => $s->sighting_date?->format('M j, Y'),
            'property_title' => $titles[$s->property_id] ?? 'Property',
            'notes' => $s->notes,
        ])->all();

        return Inertia::render('Member/Sighting/Index', [
            'sightings' => $rows,
            'new_url' => route('member.sighting.new'),
            'harvest_url' => route('member.harvest.index'),
        ]);
    }

    /** The log-a-sighting form: active leases + the sighting species vocab. */
    public function sightingNew(Request $request, LeaseService $leases, PropertyService $properties): InertiaResponse
    {
        return Inertia::render('Member/Sighting/New', [
            'leases' => $this->activeLeaseOptions($leases, $properties),
            'species' => $this->options(self::SIGHTING_SPECIES),
            'store_url' => route('member.sighting.store'),
            'index_url' => route('member.sighting.index'),
        ]);
    }

    /** Log a sighting against a lease the member has standing on. */
    public function sightingStore(Request $request, SightingService $sightings): RedirectResponse|JsonResponse
    {
        $userId = session('auth.user_id');

        $data = $request->validate([
            'lease_id' => ['required', 'uuid'],
            'species_code' => ['required', Rule::in(array_keys(self::SIGHTING_SPECIES))],
            'sighting_date' => ['required', 'date', 'before_or_equal:today'],
            'sighting_time' => ['nullable', 'date_format:H:i'],
            'count' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'gps_accuracy_m' => ['nullable', 'numeric', 'min:0'],
            'hide_location_from_members' => ['nullable', 'boolean'],
            'local_record_id' => ['nullable', 'uuid'],
        ]);

        // Standing (403) is the only guard on this write — no quota or CWD — so a
        // failure here is a genuine deny. It bubbles as a 403 (JSON for the offline
        // flush, an Inertia error page for a browser post).
        $sighting = $sightings->log($userId, $data['lease_id'], $data);

        if ($request->expectsJson()) {
            return response()->json(['id' => $sighting->id], $sighting->wasRecentlyCreated ? 201 : 200);
        }

        return redirect()->route('member.sighting.index')->with('success', 'Sighting logged.');
    }

    // ── Fishing ───────────────────────────────────────────────────────────────

    /** The member's own fishing catches, newest first. */
    public function fishingIndex(Request $request, FishingHarvestService $fishing, PropertyService $properties): InertiaResponse
    {
        $userId = session('auth.user_id');

        $logs = $fishing->listForUser($userId);
        $titles = $this->propertyTitles($logs->pluck('property_id')->all(), $properties);

        $rows = $logs->map(fn (FishingHarvestLog $f) => [
            'id' => $f->id,
            'species' => self::FISH_SPECIES[$f->species_code] ?? $f->species_code,
            'catch_date' => $f->catch_date?->format('M j, Y'),
            'property_title' => $titles[$f->property_id] ?? 'Property',
            'length_inches' => $f->length_inches,
            'weight_lbs' => $f->weight_lbs,
            'catch_and_release' => (bool) $f->catch_and_release,
            'is_public' => (bool) $f->is_public,
        ])->all();

        return Inertia::render('Member/Fishing/Index', [
            'catches' => $rows,
            'new_url' => route('member.fishing.new'),
            'harvest_url' => route('member.harvest.index'),
        ]);
    }

    /** The log-a-catch form: active leases + the fish species vocab. */
    public function fishingNew(Request $request, LeaseService $leases, PropertyService $properties): InertiaResponse
    {
        return Inertia::render('Member/Fishing/New', [
            'leases' => $this->activeLeaseOptions($leases, $properties),
            'species' => $this->options(self::FISH_SPECIES),
            'store_url' => route('member.fishing.store'),
            'index_url' => route('member.fishing.index'),
        ]);
    }

    /** Log a fishing catch against a lease the member has standing on. */
    public function fishingStore(Request $request, FishingHarvestService $fishing): RedirectResponse|JsonResponse
    {
        $userId = session('auth.user_id');

        $data = $request->validate([
            'lease_id' => ['required', 'uuid'],
            'species_code' => ['required', Rule::in(array_keys(self::FISH_SPECIES))],
            'catch_date' => ['required', 'date', 'before_or_equal:today'],
            'catch_time' => ['nullable', 'date_format:H:i'],
            'length_inches' => ['nullable', 'numeric', 'min:0', 'max:999'],
            'weight_lbs' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'catch_and_release' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_public' => ['nullable', 'boolean'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'gps_accuracy_m' => ['nullable', 'numeric', 'min:0'],
            'local_record_id' => ['nullable', 'uuid'],
        ]);

        // Standing (403) is the only guard — no quota or CWD on a fishing catch.
        $catch = $fishing->log($userId, $data['lease_id'], $data);

        if ($request->expectsJson()) {
            return response()->json(['id' => $catch->id], $catch->wasRecentlyCreated ? 201 : 200);
        }

        return redirect()->route('member.fishing.index')->with('success', 'Catch logged.');
    }

    /**
     * The member's active leases shaped as form options ({id, property_title,
     * end_date}). Shared by the sighting/fishing "new" forms.
     *
     * @return array<int,array{id:string,property_title:string,end_date:?string}>
     */
    private function activeLeaseOptions(LeaseService $leases, PropertyService $properties): array
    {
        $userId = session('auth.user_id');
        $active = $leases->getActiveLeasesForLessee($userId);
        $titles = $this->propertyTitles($active->pluck('property_id')->all(), $properties);

        return $active->map(fn ($lease) => [
            'id' => $lease->id,
            'property_title' => $titles[$lease->property_id] ?? 'Property',
            'end_date' => $lease->end_date?->format('M j, Y'),
        ])->values()->all();
    }

    /**
     * The subset of the given document ids that have cleared the virus scan
     * (status='ready'), as an id-keyed set for O(1) lookups. One cross-DB (DB 11)
     * query for the whole page. Unscanned or quarantined photos never get a URL,
     * and the serve route itself is owner-only.
     *
     * @param  array<int,string>  $documentIds
     * @return array<string,true>
     */
    private function readyPhotoIds(array $documentIds): array
    {
        if ($documentIds === []) {
            return [];
        }

        return Document::whereIn('id', array_unique($documentIds))
            ->where('status', 'ready')
            ->whereNull('deleted_at')
            ->pluck('id')
            ->mapWithKeys(fn (string $id) => [$id => true])
            ->all();
    }

    /**
     * Resolve a set of property ids to their titles in one pass. Cross-DB (DB 2)
     * lookup via the service — never an Eloquent relation.
     *
     * @param  array<int,string>  $propertyIds
     * @return array<string,string>
     */
    private function propertyTitles(array $propertyIds, PropertyService $properties): array
    {
        $titles = [];
        foreach (array_unique($propertyIds) as $propertyId) {
            $property = rescue(fn () => $properties->find($propertyId), null);
            $titles[$propertyId] = $property?->title ?? 'Property';
        }

        return $titles;
    }

    /**
     * Shape a code => label map into ordered {value,label} option objects for a
     * front-end <select>.
     *
     * @param  array<string,string>  $map
     * @return array<int,array{value:string,label:string}>
     */
    private function options(array $map): array
    {
        $options = [];
        foreach ($map as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label];
        }

        return $options;
    }
}
