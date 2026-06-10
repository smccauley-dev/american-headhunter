<?php

namespace App\Services\Property;

use App\Models\Property\Property;
use App\Models\Property\PropertyListing;
use App\Models\Property\PropertyAccessInfo;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PropertyService extends BaseService
{
    private const VALID_SPECIES_CODES = [
        'whitetail_deer', 'mule_deer', 'turkey', 'waterfowl', 'dove', 'hog',
        'elk', 'bear', 'antelope', 'pheasant', 'quail', 'rabbit', 'squirrel',
        'coyote', 'other',
    ];

    public function __construct(
        private readonly GeospatialService $geospatialService,
    ) {}

    // ─── Reads ───────────────────────────────────────────────────────────────────

    /**
     * Find a property by UUID. Uses the read replica.
     */
    public function find(string $propertyId): ?Property
    {
        return Property::on('property_read')
            ->with(['photos', 'species', 'rules'])
            ->find($propertyId);
    }

    /**
     * Find a property by slug. Uses the read replica.
     */
    public function findBySlug(string $slug): ?Property
    {
        return Property::on('property_read')
            ->with(['activeListings', 'photos', 'species', 'rules'])
            ->where('slug', $slug)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->first();
    }

    /**
     * Get all active properties owned by a user.
     */
    public function getPropertiesForOwner(string $ownerUserId): \Illuminate\Database\Eloquent\Collection
    {
        return Property::on('property_read')
            ->where('owner_user_id', $ownerUserId)
            ->whereNull('deleted_at')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Find an active listing by UUID. Uses the read replica.
     */
    public function findListing(string $listingId): ?PropertyListing
    {
        return PropertyListing::on('property_read')
            ->with(['property', 'amenities'])
            ->find($listingId);
    }

    /**
     * Search active public listings with filters. Uses the read replica.
     * Returns paginated results.
     *
     * @param  array{
     *   state_code?: string,
     *   county?: string,
     *   listing_type?: string,
     *   species?: string[],
     *   min_acres?: float,
     *   max_acres?: float,
     *   min_price?: float,
     *   max_price?: float,
     *   page?: int,
     *   per_page?: int,
     * } $filters
     */
    public function searchListings(array $filters): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        // Paginated results with Eloquent relations are not cached in Valkey — serializing
        // model graphs across requests is fragile and each page/filter combo is unique.
        $query = PropertyListing::on('property_read')
            ->with(['property', 'property.species'])
            ->where('property_listings.status', 'active')
            ->whereNull('property_listings.deleted_at')
            ->join('properties', 'properties.id', '=', 'property_listings.property_id')
            ->where('properties.status', 'active')
            ->whereNull('properties.deleted_at')
            ->where('property_listings.visibility', 'public');

        if (! empty($filters['state_code'])) {
            $query->where('properties.state_code', $filters['state_code']);
        }

        if (! empty($filters['county'])) {
            $query->where('properties.county', $filters['county']);
        }

        if (! empty($filters['listing_type'])) {
            $query->where('property_listings.listing_type', $filters['listing_type']);
        }

        if (! empty($filters['min_acres'])) {
            $query->where('properties.total_acres', '>=', $filters['min_acres']);
        }

        if (! empty($filters['max_acres'])) {
            $query->where('properties.total_acres', '<=', $filters['max_acres']);
        }

        if (! empty($filters['min_price'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('property_listings.price_per_hunter', '>=', $filters['min_price'])
                  ->orWhere('property_listings.price_total', '>=', $filters['min_price']);
            });
        }

        if (! empty($filters['max_price'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('property_listings.price_per_hunter', '<=', $filters['max_price'])
                  ->orWhere('property_listings.price_total', '<=', $filters['max_price']);
            });
        }

        if (! empty($filters['species'])) {
            $species = array_values(array_intersect(
                (array) $filters['species'],
                self::VALID_SPECIES_CODES
            ));
            if (! empty($species)) {
                $query->whereIn('properties.id', function ($sub) use ($species) {
                    $sub->select('property_id')
                        ->from('property_species')
                        ->whereIn('species_code', $species);
                });
            }
        }

        $perPage = min((int) ($filters['per_page'] ?? 20), 50);

        return $query
            ->select('property_listings.*')
            ->orderBy('properties.state_code')
            ->orderBy('properties.county')
            ->paginate($perPage, ['*'], 'page', $filters['page'] ?? 1);
    }

    // ─── Writes ──────────────────────────────────────────────────────────────────

    /**
     * Create a new property for an owner.
     */
    public function createProperty(string $ownerUserId, array $attributes): Property
    {
        $property = Property::on('property')->create([
            'owner_user_id' => $ownerUserId,
            'slug'          => $this->generateSlug($attributes['title']),
            ...$attributes,
        ]);

        $this->invalidate("property:landowner:{$ownerUserId}");

        return $property;
    }

    /**
     * Update a property's core attributes.
     */
    public function updateProperty(string $propertyId, array $attributes): Property
    {
        $property = Property::on('property')->findOrFail($propertyId);
        $oldSlug  = $property->slug;

        if (isset($attributes['title']) && $attributes['title'] !== $property->title) {
            $attributes['slug'] = $this->generateSlug($attributes['title']);
        }

        $property->update($attributes);
        $newSlug = $attributes['slug'] ?? $oldSlug;

        $this->invalidatePropertyCache($propertyId, $oldSlug, $property->owner_user_id);

        // If the slug changed, the new slug key must also be cleared so stale
        // 404 responses don't linger in Valkey under the new key.
        if ($newSlug !== $oldSlug) {
            $this->invalidate("property:slug:{$newSlug}");
        }

        return $property->fresh();
    }

    /**
     * Soft-delete a property and all its listings.
     */
    public function deleteProperty(string $propertyId): void
    {
        $property = Property::on('property')->findOrFail($propertyId);
        $property->delete();
        $this->invalidatePropertyCache($propertyId, $property->slug, $property->owner_user_id);
    }

    /**
     * Create a listing for a property.
     */
    public function createListing(string $propertyId, array $attributes): PropertyListing
    {
        $listing = PropertyListing::on('property')->create([
            'property_id' => $propertyId,
            ...$attributes,
        ]);

        $this->invalidate("property:{$propertyId}");

        return $listing;
    }

    /**
     * Publish a draft listing (sets status to 'active').
     */
    public function publishListing(string $listingId): PropertyListing
    {
        $listing = PropertyListing::on('property')->findOrFail($listingId);
        $listing->update(['status' => 'active']);
        $this->invalidate("listing:{$listingId}", "property:{$listing->property_id}");

        return $listing->fresh();
    }

    // ─── Access Info (encrypted) ──────────────────────────────────────────────

    /**
     * Decrypt and return access info for a property.
     *
     * The caller MUST pass $callerHasVerifiedLease = true only after confirming
     * the requesting user holds an active lease for this property. This is enforced
     * structurally — the method throws if the flag is not explicitly set.
     * Phase 4 LeaseService will provide the verification helper.
     *
     * @throws \RuntimeException if called without lease verification or no access info exists
     */
    public function getAccessInfo(string $propertyId, string $encryptionKey, bool $callerHasVerifiedLease = false): array
    {
        if (! $callerHasVerifiedLease) {
            throw new \RuntimeException(
                'getAccessInfo requires active lease verification. Pass $callerHasVerifiedLease = true only after confirming the user holds an active lease.'
            );
        }

        $row = PropertyAccessInfo::on('property')
            ->where('property_id', $propertyId)
            ->first();

        if (! $row) {
            return [];
        }

        $decrypted = DB::connection('property')->selectOne(
            'SELECT pgp_sym_decrypt(access_info_encrypted::bytea, ?) AS plain FROM property_access_info WHERE property_id = ?',
            [$encryptionKey, $propertyId]
        );

        return json_decode($decrypted?->plain ?? '{}', true);
    }

    /**
     * Write (or update) encrypted access info for a property.
     */
    public function setAccessInfo(string $propertyId, array $accessData, string $encryptionKey, string $updatedByUserId): void
    {
        $json = json_encode($accessData);

        DB::connection('property')->statement(
            'INSERT INTO property_access_info (id, property_id, access_info_encrypted, updated_by_user_id)
             VALUES (gen_random_uuid(), ?, pgp_sym_encrypt(?, ?), ?)
             ON CONFLICT (property_id) DO UPDATE
             SET access_info_encrypted = pgp_sym_encrypt(?, ?),
                 updated_at = NOW(),
                 updated_by_user_id = ?',
            [$propertyId, $json, $encryptionKey, $updatedByUserId, $json, $encryptionKey, $updatedByUserId]
        );
    }

    // ─── Record a view ────────────────────────────────────────────────────────

    /**
     * Append a view event. Fire-and-forget — use only from a queued job.
     */
    public function recordView(string $listingId, ?string $userId, ?string $ipAddress): void
    {
        DB::connection('property')->table('property_views')->insert([
            'id'         => (string) Str::uuid(),
            'listing_id' => $listingId,
            'user_id'    => $userId,
            'ip_address' => $ipAddress,
            'created_at' => now(),
        ]);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function generateSlug(string $title): string
    {
        $base = Str::slug($title);
        $slug = $base;
        $i    = 1;

        while (
            DB::connection('property')
                ->table('properties')
                ->where('slug', $slug)
                ->whereNull('deleted_at')
                ->exists()
        ) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    private function invalidatePropertyCache(string $propertyId, string $slug, string $ownerUserId): void
    {
        $this->invalidate(
            "property:{$propertyId}",
            "property:slug:{$slug}",
            "property:landowner:{$ownerUserId}"
        );
        $this->geospatialService->invalidatePropertyCache($propertyId);
    }
}
