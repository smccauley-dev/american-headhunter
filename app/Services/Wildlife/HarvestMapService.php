<?php

namespace App\Services\Wildlife;

use App\Database\ConnectionRole;
use App\Models\Documents\Document;
use App\Models\Identity\ProfilePhoto;
use App\Models\Identity\User;
use App\Models\Wildlife\HarvestLog;
use App\Models\Wildlife\WildlifeSighting;
use App\Services\Lease\LeaseService;
use App\Services\Property\GeospatialService;
use App\Services\Property\PropertyMapService;
use App\Services\Property\PropertyService;
use Illuminate\Support\Collection;

/**
 * Assembles the member GPS map for a property: the PostGIS boundary + stands
 * (DB 13), the landowner's georeferenced map markers (DB 2), and every
 * co-hunter's harvest/sighting points (DB 5 records zipped with their DB 13
 * coordinates and DB 1 hunter names) — all at the service layer, never a SQL
 * join across connections.
 *
 * SEC-024 spine: precise on-property GPS is member-only. The gate is past-or-
 * present standing on the property (lessee or approved hunter on ANY of its
 * leases) or property management; nothing here is ever rendered publicly.
 * Per-record `hide_location_from_members` removes a point from everyone's map
 * except its own hunter's.
 */
class HarvestMapService
{
    public function __construct(
        private readonly LeaseService $leases,
        private readonly PropertyService $properties,
        private readonly PropertyMapService $propertyMaps,
        private readonly GeospatialService $geo,
    ) {}

    /** The map's viewing gate — past/present hunter of the property, or its manager. */
    public function canView(string $userId, string $propertyId): bool
    {
        return $this->leases->userHasOrHadStandingOnProperty($userId, $propertyId)
            || $this->properties->userCanManageProperty($userId, $propertyId);
    }

    /**
     * The full map payload for a viewer, or null when they have no standing.
     *
     * @return array{boundary: ?array, stands: array, landowner_markers: list<array<string,mixed>>, features: list<array<string,mixed>>}|null
     */
    public function forProperty(string $viewerId, string $propertyId): ?array
    {
        if (! $this->canView($viewerId, $propertyId)) {
            return null;
        }

        $harvests = $this->visibleRecords(HarvestLog::on('wildlife'), $viewerId, $propertyId);
        $sightings = $this->visibleRecords(WildlifeSighting::on('wildlife'), $viewerId, $propertyId);

        $points = $this->geo->pointsByIds(
            $harvests->pluck('location_geospatial_id')
                ->merge($sightings->pluck('location_geospatial_id'))
                ->all()
        );

        $names = $this->hunterNames(
            $harvests->pluck('user_id')->merge($sightings->pluck('user_id'))->unique()->values()
        );

        $photoUrls = $this->harvestPhotoUrls($harvests, $viewerId);

        $features = [];

        foreach ($harvests as $h) {
            $point = $points[$h->location_geospatial_id] ?? null;
            if ($point === null) {
                continue;
            }
            $features[] = [
                'id' => $h->id,
                'type' => 'harvest',
                'species' => $this->label($h->species_code),
                'hunter_name' => $names[$h->user_id] ?? 'Hunter',
                'is_own' => $h->user_id === $viewerId,
                'date' => $h->harvest_date?->format('M j, Y'),
                'photo_url' => $photoUrls[$h->id] ?? null,
                'lng' => $point['lng'],
                'lat' => $point['lat'],
            ];
        }

        foreach ($sightings as $s) {
            $point = $points[$s->location_geospatial_id] ?? null;
            if ($point === null) {
                continue;
            }
            $features[] = [
                'id' => $s->id,
                'type' => 'sighting',
                'species' => $this->label($s->species_code),
                'hunter_name' => $names[$s->user_id] ?? 'Hunter',
                'is_own' => $s->user_id === $viewerId,
                'date' => $s->sighting_date?->format('M j, Y'),
                'count' => (int) $s->count,
                'lng' => $point['lng'],
                'lat' => $point['lat'],
            ];
        }

        return [
            'boundary' => $this->geo->getPropertyBoundaryGeoJson($propertyId),
            'stands' => $this->geo->getPropertyStandsGeoJson($propertyId),
            'landowner_markers' => $this->landownerMarkers($propertyId),
            'features' => $features,
        ];
    }

    /**
     * The property's non-deleted field records that carry a GPS point, with
     * hidden spots filtered out for everyone but their own hunter.
     */
    private function visibleRecords($query, string $viewerId, string $propertyId): Collection
    {
        return $query
            ->where('property_id', $propertyId)
            ->whereNull('deleted_at')
            ->whereNotNull('location_geospatial_id')
            ->where(fn ($q) => $q
                ->where('hide_location_from_members', false)
                ->orWhere('user_id', $viewerId))
            ->get();
    }

    /**
     * Hunter display names, batched from DB 1. The identity table default-denies
     * other users' rows under ah_runtime (SEC-047), so — the viewer already being
     * property-authorized — the lookup runs under ah_system.
     */
    private function hunterNames(Collection $userIds): array
    {
        $ids = $userIds->filter()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        $users = ConnectionRole::asSystem(
            fn () => User::on('identity')->with('profile')->whereIn('id', $ids)->get()
        );

        return $users
            ->mapWithKeys(fn (User $u) => [$u->id => $u->profile?->full_name ?: 'Hunter'])
            ->all();
    }

    /**
     * One popup photo per harvest: the first attached photo that has cleared the
     * virus scan and — for anyone but its own hunter — does not retain location
     * metadata (SEC-061: a kept-EXIF file would leak the exact coordinates to
     * whoever downloads it). URLs point at the standing-gated member route, not
     * the owner-only profile serve.
     *
     * @return array<string,string> harvest id => url
     */
    private function harvestPhotoUrls(Collection $harvests, string $viewerId): array
    {
        $allIds = $harvests->flatMap(fn (HarvestLog $h) => $h->field_photos ?? [])->unique()->values();
        if ($allIds->isEmpty()) {
            return [];
        }

        $ready = Document::whereIn('id', $allIds)
            ->where('status', 'ready')
            ->whereNull('deleted_at')
            ->pluck('id')
            ->flip()
            ->all();

        $locationPrivate = ProfilePhoto::whereIn('document_id', $allIds)
            ->where('is_location_private', true)
            ->pluck('document_id')
            ->flip()
            ->all();

        $urls = [];
        foreach ($harvests as $h) {
            $own = $h->user_id === $viewerId;
            foreach ($h->field_photos ?? [] as $docId) {
                if (! isset($ready[$docId])) {
                    continue;
                }
                if (! $own && isset($locationPrivate[$docId])) {
                    continue;
                }
                $urls[$h->id] = route('member.harvest-photos.show', $docId);
                break;
            }
        }

        return $urls;
    }

    /**
     * The landowner's map markers that carry real coordinates (the boundary
     * overlay's percent-placed pins with lat/lng filled in), shaped for the map.
     *
     * @return list<array<string,mixed>>
     */
    private function landownerMarkers(string $propertyId): array
    {
        $overlay = $this->propertyMaps->getBoundaryOverlay($propertyId);
        if ($overlay === null) {
            return [];
        }

        return collect($overlay['markers'])
            ->filter(fn (array $m) => ($m['latitude'] ?? null) !== null && ($m['longitude'] ?? null) !== null)
            ->map(fn (array $m) => [
                'id' => $m['id'],
                'label' => $m['label'] ?: $m['type_label'],
                'type' => $m['type'],
                'type_label' => $m['type_label'],
                'color' => $m['color'],
                'notes' => $m['notes'],
                'lng' => (float) $m['longitude'],
                'lat' => (float) $m['latitude'],
            ])
            ->values()
            ->all();
    }

    private function label(string $speciesCode): string
    {
        return ucwords(str_replace('_', ' ', $speciesCode));
    }
}
