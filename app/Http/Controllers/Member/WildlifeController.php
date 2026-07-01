<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Models\Wildlife\FishingHarvestLog;
use App\Models\Wildlife\HarvestLog;
use App\Models\Wildlife\HarvestQuota;
use App\Models\Wildlife\WildlifeSighting;
use App\Services\Lease\LeaseService;
use App\Services\Property\PropertyService;
use App\Services\Wildlife\FishingHarvestService;
use App\Services\Wildlife\HarvestService;
use App\Services\Wildlife\QuotaService;
use App\Services\Wildlife\SightingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
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

        $rows = $logs->map(fn (HarvestLog $h) => [
            'id' => $h->id,
            'species' => self::SPECIES[$h->species_code] ?? $h->species_code,
            'weapon' => self::WEAPONS[$h->weapon_type] ?? $h->weapon_type,
            'harvest_date' => $h->harvest_date?->format('M j, Y'),
            'property_title' => $titles[$h->property_id] ?? 'Property',
            'antler_score' => $h->antler_score,
            'is_public' => (bool) $h->is_public,
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
    public function harvestStore(Request $request, HarvestService $harvests): RedirectResponse
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
            'cwd_acknowledged' => ['nullable', 'boolean'],
        ]);

        try {
            $harvests->log($userId, $data['lease_id'], $data);
        } catch (HttpException $e) {
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

        return redirect()->route('member.harvest.index')->with('success', 'Harvest logged.');
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
    public function sightingStore(Request $request, SightingService $sightings): RedirectResponse
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
        ]);

        // Standing (403) is the only guard on this write — no quota or CWD — so a
        // failure here is a genuine deny and is allowed to surface as a 403.
        $sightings->log($userId, $data['lease_id'], $data);

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
    public function fishingStore(Request $request, FishingHarvestService $fishing): RedirectResponse
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
        ]);

        // Standing (403) is the only guard — no quota or CWD on a fishing catch.
        $fishing->log($userId, $data['lease_id'], $data);

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
