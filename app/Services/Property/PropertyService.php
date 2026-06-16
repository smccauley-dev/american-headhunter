<?php

namespace App\Services\Property;

use App\Models\Property\Property;
use App\Models\Property\PropertyListing;
use App\Models\Property\PropertyManager;
use App\Models\Property\PropertyContact;
use App\Models\Property\PropertyMapImage;
use App\Models\Property\PropertyMapMarker;
use App\Models\Property\PropertyAccessInfo;
use App\Models\Property\PropertyAmenity;
use App\Models\Property\PropertyAvailability;
use App\Models\Property\PropertyPhoto;
use App\Models\Property\PropertyRule;
use App\Models\Property\PropertySpecies;
use App\Services\BaseService;
use App\Services\Documents\DocumentService;
use App\Support\PhoneNumber;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PropertyService extends BaseService
{
    private const VALID_SPECIES_CODES = [
        'whitetail_deer', 'mule_deer', 'turkey', 'waterfowl', 'dove', 'hog',
        'elk', 'bear', 'antelope', 'pheasant', 'quail', 'rabbit', 'squirrel',
        'coyote', 'other',
    ];

    /** Game-type species codes → display labels (mirrors PropertyFormV2). */
    public const SPECIES_LABELS = [
        'whitetail_deer' => 'Whitetail Deer',
        'mule_deer'      => 'Mule Deer',
        'turkey'         => 'Turkey',
        'waterfowl'      => 'Waterfowl',
        'dove'           => 'Dove',
        'hog'            => 'Hog',
        'elk'            => 'Elk',
        'bear'           => 'Bear',
        'antelope'       => 'Antelope',
        'pheasant'       => 'Pheasant',
        'quail'          => 'Quail',
        'rabbit'         => 'Rabbit',
        'squirrel'       => 'Squirrel',
        'coyote'         => 'Coyote',
        'other'          => 'Other',
    ];

    public function __construct(
        private readonly GeospatialService $geospatialService,
        private readonly DocumentService   $documentService,
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
     * Properties the user owns — directly via owner_user_id or via an
     * 'owner' grant in property_managers. Plain arrays for the admin user
     * detail page. Cached 5 min.
     */
    public function getOwnedPropertySummaries(string $userId): array
    {
        return $this->cache("property:user:{$userId}:owned_summaries", function () use ($userId) {
            $direct = Property::on('property_read')
                ->where('owner_user_id', $userId)
                ->whereNull('deleted_at')
                ->get(['id', 'title', 'state_code', 'status']);

            $grantedIds = PropertyManager::on('property_read')
                ->where('user_id', $userId)
                ->where('role', 'owner')
                ->whereNull('revoked_at')
                ->pluck('property_id');

            $granted = Property::on('property_read')
                ->whereIn('id', $grantedIds)
                ->whereNull('deleted_at')
                ->get(['id', 'title', 'state_code', 'status']);

            return $direct->merge($granted)
                ->unique('id')
                ->map(fn ($p) => [
                    'title'      => $p->title,
                    'state_code' => $p->state_code,
                    'status'     => $p->status,
                ])->values()->all();
        }, 5);
    }

    /**
     * Active co-owner / manager / operator grants for the user — plain
     * arrays for the admin user detail page. Cached 5 min.
     */
    public function getManagerGrantSummaries(string $userId): array
    {
        return $this->cache("property:user:{$userId}:manager_grants", function () use ($userId) {
            return PropertyManager::on('property_read')
                ->where('user_id', $userId)
                ->whereNull('revoked_at')
                ->whereIn('role', ['co_owner', 'manager', 'operator'])
                ->with('property')
                ->get()
                ->map(fn ($g) => [
                    'property_title' => $g->property?->title ?? '—',
                    'role'           => $g->role,
                    'granted_at'     => $g->granted_at?->format('M j, Y'),
                ])->all();
        }, 5);
    }

    /**
     * Properties a landowner owns or actively manages, assembled for the member
     * portal "My Properties" blade. Each row carries listing counts and the
     * user's role on that property. Owned = direct owner_user_id OR an active
     * 'owner' grant; managed = an active co_owner/manager/operator grant. Not
     * cached: a freshly created property must appear immediately.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getManagedPropertySummaries(string $userId): array
    {
        $grants = PropertyManager::on('property_read')
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->get(['property_id', 'role'])
            ->keyBy('property_id');

        $ids = Property::on('property_read')
            ->where('owner_user_id', $userId)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->merge($grants->keys())
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $properties = Property::on('property_read')
            ->withCount([
                'listings as listings_count',
                'listings as active_listings_count' => fn ($q) => $q->where('status', 'active'),
            ])
            ->whereIn('id', $ids)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->get();

        return $properties->map(function (Property $p) use ($userId, $grants) {
            $role = $p->owner_user_id === $userId
                ? 'owner'
                : ($grants->get($p->id)?->role ?? 'manager');

            return [
                'id'                    => $p->id,
                'title'                 => $p->title,
                'slug'                  => $p->slug,
                'county'                => $p->county,
                'state_code'            => $p->state_code,
                'status'                => $p->status,
                'total_acres'           => $p->total_acres !== null ? (float) $p->total_acres : null,
                'huntable_acres'        => $p->huntable_acres !== null ? (float) $p->huntable_acres : null,
                'role'                  => $role,
                'listings_count'        => (int) $p->listings_count,
                'active_listings_count' => (int) $p->active_listings_count,
                'primary_photo_url'     => $p->primary_photo_document_id
                    ? route('property-photos.show', $p->primary_photo_document_id)
                    : null,
            ];
        })->all();
    }

    /**
     * Whether a user may manage a property from the member portal — true if they
     * own it or hold an active (non-revoked) manager grant of any role. The
     * `properties` table has no RLS policy, so member-portal property management
     * must scope every read and write through this check. Mirrors exactly the
     * set surfaced by getManagedPropertySummaries(), so no blade link 404s.
     */
    public function userCanManageProperty(string $userId, string $propertyId): bool
    {
        $owns = Property::on('property_read')
            ->where('id', $propertyId)
            ->where('owner_user_id', $userId)
            ->whereNull('deleted_at')
            ->exists();

        if ($owns) {
            return true;
        }

        return PropertyManager::on('property_read')
            ->where('property_id', $propertyId)
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->exists();
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
     * Booked / blocked / maintenance date ranges for a listing — the dates a
     * day-hunt applicant cannot select. Returns inclusive ISO date ranges.
     *
     * @return list<array{start: string, end: string, reason: string}>
     */
    public function getUnavailableRanges(string $listingId): array
    {
        return PropertyAvailability::on('property_read')
            ->where('listing_id', $listingId)
            ->orderBy('date_start')
            ->get(['date_start', 'date_end', 'reason'])
            ->map(fn (PropertyAvailability $r) => [
                'start'  => $r->date_start->toDateString(),
                'end'    => $r->date_end->toDateString(),
                'reason' => $r->reason,
            ])
            ->all();
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

    /**
     * Update a listing's attributes.
     */
    public function updateListing(string $listingId, array $attributes): PropertyListing
    {
        $listing = PropertyListing::on('property')->findOrFail($listingId);
        $listing->update($attributes);
        $this->invalidate("listing:{$listingId}", "property:{$listing->property_id}");

        return $listing->fresh();
    }

    /**
     * Soft-delete a listing.
     */
    public function deleteListing(string $listingId): void
    {
        $listing = PropertyListing::on('property')->findOrFail($listingId);
        $listing->delete();
        $this->invalidate("listing:{$listingId}", "property:{$listing->property_id}");
    }

    /**
     * All non-deleted listings for a property, newest first. Read replica.
     */
    public function getListingsForProperty(string $propertyId): \Illuminate\Database\Eloquent\Collection
    {
        return PropertyListing::on('property_read')
            ->where('property_id', $propertyId)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * A listing scoped to its property — guards that {listing} actually belongs to
     * {property} before any member-portal edit/delete. Read replica.
     */
    public function findListingForProperty(string $propertyId, string $listingId): ?PropertyListing
    {
        return PropertyListing::on('property_read')
            ->where('id', $listingId)
            ->where('property_id', $propertyId)
            ->whereNull('deleted_at')
            ->first();
    }

    // ─── Property details: species, rules, amenities ─────────────────────────

    /** Game-type species rows for a property (read replica). */
    public function getSpeciesFor(string $propertyId): array
    {
        return PropertySpecies::on('property_read')
            ->where('property_id', $propertyId)
            ->orderByDesc('is_primary')
            ->orderBy('species_code')
            ->get(['species_code', 'is_primary'])
            ->map(fn (PropertySpecies $s) => [
                'species_code' => $s->species_code,
                'is_primary'   => (bool) $s->is_primary,
            ])
            ->all();
    }

    /** Property rules ordered by sort_order (read replica). */
    public function getRulesFor(string $propertyId): array
    {
        return PropertyRule::on('property_read')
            ->where('property_id', $propertyId)
            ->orderBy('sort_order')
            ->get(['rule_text'])
            ->map(fn (PropertyRule $r) => ['rule_text' => $r->rule_text])
            ->all();
    }

    /** Amenity ids currently offered on a property (read replica). */
    public function getAmenityIdsFor(string $propertyId): array
    {
        return DB::connection('property_read')
            ->table('property_amenity_offerings')
            ->where('property_id', $propertyId)
            ->pluck('amenity_id')
            ->all();
    }

    /** Full amenity catalogue grouped by category, for the picker (read replica). */
    public function getAmenityCatalog(): array
    {
        return PropertyAmenity::on('property_read')
            ->orderBy('category')
            ->orderBy('name')
            ->get(['id', 'name', 'category'])
            ->groupBy('category')
            ->map(fn ($items, $category) => [
                'category' => $category,
                'label'    => PropertyAmenity::categoryLabel($category),
                'items'    => $items->map(fn ($a) => ['id' => $a->id, 'name' => $a->name])->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Replace a property's game-type species and rules, and sync its amenity
     * offerings, in one transaction. Species and rules are full-replace
     * (hard delete + reinsert — both are non-soft-delete child tables); rule
     * order follows array position. Inputs are validated by the controller.
     *
     * @param  array<int, array{species_code: string, is_primary?: bool}>  $species
     * @param  array<int, array{rule_text: string}>                        $rules
     * @param  array<int, string>                                          $amenityIds
     */
    public function saveDetails(string $propertyId, array $species, array $rules, array $amenityIds): void
    {
        DB::connection('property')->transaction(function () use ($propertyId, $species, $rules, $amenityIds) {
            PropertySpecies::on('property')->where('property_id', $propertyId)->delete();
            foreach ($species as $s) {
                PropertySpecies::on('property')->create([
                    'property_id'  => $propertyId,
                    'species_code' => $s['species_code'],
                    'is_primary'   => (bool) ($s['is_primary'] ?? false),
                ]);
            }

            PropertyRule::on('property')->where('property_id', $propertyId)->delete();
            foreach (array_values($rules) as $i => $r) {
                PropertyRule::on('property')->create([
                    'property_id' => $propertyId,
                    'rule_text'   => $r['rule_text'],
                    'sort_order'  => $i,
                ]);
            }

            Property::on('property')->findOrFail($propertyId)
                ->amenities()->sync($amenityIds);
        });

        $property = Property::on('property')->find($propertyId);
        if ($property) {
            $this->invalidatePropertyCache($propertyId, $property->slug, $property->owner_user_id);
        }
    }

    // ─── Managers ─────────────────────────────────────────────────────────────

    /**
     * Active managers on a property with cross-DB-resolved identities, for the
     * member-portal team view. Read replica + a single identity bulk-load — no
     * Eloquent cross-DB relationship.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getManagersForProperty(string $propertyId): array
    {
        $managers = PropertyManager::on('property_read')
            ->where('property_id', $propertyId)
            ->whereNull('revoked_at')
            ->orderBy('granted_at')
            ->get();

        if ($managers->isEmpty()) {
            return [];
        }

        $userIds = $managers->pluck('user_id')
            ->merge($managers->pluck('granted_by_user_id'))
            ->filter()->unique()->values()->all();

        $users = \App\Models\Identity\User::on('identity')
            ->with('profile')
            ->whereIn('id', $userIds)
            ->get()
            ->keyBy('id');

        return $managers->map(function (PropertyManager $m) use ($users) {
            $user      = $users->get($m->user_id);
            $grantedBy = $users->get($m->granted_by_user_id);

            return [
                'id'         => $m->id,
                'name'       => $user?->profile?->full_name ?: ($user?->email ?? '—'),
                'email'      => $user?->email ?? '',
                'role'       => $m->role,
                'granted_at' => $m->granted_at?->format('M j, Y'),
                'granted_by' => $grantedBy?->profile?->full_name ?: ($grantedBy?->email ?? '—'),
            ];
        })->all();
    }

    /**
     * Grant a manager role on a property to the user with the given email. Returns
     * a status array — ok=false carries a user-facing message (no such user /
     * already a manager). The actor is recorded as granted_by.
     *
     * @return array{ok: bool, message: string}
     */
    public function grantManager(string $propertyId, string $email, string $role, string $grantedByUserId): array
    {
        $user = app(\App\Services\Identity\UserService::class)->findByEmail($email);

        if (! $user) {
            return ['ok' => false, 'message' => 'No user found with that email address.'];
        }

        $exists = PropertyManager::on('property')
            ->where('property_id', $propertyId)
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->exists();

        if ($exists) {
            return ['ok' => false, 'message' => 'That user already has active manager access.'];
        }

        PropertyManager::on('property')->create([
            'property_id'        => $propertyId,
            'user_id'            => $user->id,
            'role'               => $role,
            'granted_by_user_id' => $grantedByUserId,
            'granted_at'         => now(),
        ]);

        $this->invalidate(
            "property:user:{$user->id}:manager_grants",
            "property:user:{$user->id}:owned_summaries",
        );

        return ['ok' => true, 'message' => 'Manager access granted.'];
    }

    /**
     * Revoke a manager grant scoped to its property. Returns false when no active
     * grant with that id exists on the property.
     */
    public function revokeManager(string $propertyId, string $managerId): bool
    {
        $manager = PropertyManager::on('property')
            ->where('property_id', $propertyId)
            ->where('id', $managerId)
            ->whereNull('revoked_at')
            ->first();

        if (! $manager) {
            return false;
        }

        $manager->revoked_at = now();
        $manager->save();

        $this->invalidate(
            "property:user:{$manager->user_id}:manager_grants",
            "property:user:{$manager->user_id}:owned_summaries",
        );

        return true;
    }

    // ─── Access Info (encrypted) ──────────────────────────────────────────────

    /**
     * Decrypt and return access info for a property.
     *
     * Access is gated structurally (SEC-003-P4): the service itself confirms the
     * requesting user holds an active lease on the property — as lessee or lessor —
     * by calling LeaseService::userHasActiveLeaseForProperty(). Callers can no
     * longer assert verification with a trusted bool flag, so a forgotten or
     * spoofed flag can never expose gate codes.
     *
     * @throws \RuntimeException if the requesting user has no active lease for this property
     */
    public function getAccessInfo(string $propertyId, string $requestingUserId, string $encryptionKey): array
    {
        $hasActiveLease = app(\App\Services\Lease\LeaseService::class)
            ->userHasActiveLeaseForProperty($requestingUserId, $propertyId);

        if (! $hasActiveLease) {
            throw new \RuntimeException(
                'getAccessInfo denied: requesting user has no active lease for this property.'
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
     *
     * SEC-007: gate-code writes are throttled (10/min per property per user) so a
     * compromised or abusive staff session cannot rapidly overwrite access
     * credentials, and every change is audit-logged (without the secret values).
     */
    public function setAccessInfo(string $propertyId, array $accessData, string $encryptionKey, string $updatedByUserId): void
    {
        $rateKey = "set-access-info:{$propertyId}:{$updatedByUserId}";
        if (! \Illuminate\Support\Facades\RateLimiter::attempt($rateKey, 10, fn () => true, 60)) {
            throw new \RuntimeException('Access-info update rate limit exceeded. Try again shortly.');
        }

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

        // Record only that access info changed and which keys were present — never
        // the gate codes / wifi passwords themselves (CLAUDE.md encryption rules).
        app(\App\Services\Audit\AuditService::class)->log(
            eventType:      'property_access_info_updated',
            sourceDatabase: 'ah_property',
            tableName:      'property_access_info',
            recordId:       $propertyId,
            userId:         $updatedByUserId,
            actionSummary:  'Property access info (gate codes) updated',
            changedFields:  array_keys($accessData),
        );
    }

    // ─── Contact directory ──────────────────────────────────────────────────────

    /**
     * Assemble the property's contact directory for a hunter in the field.
     *
     * Landowner and property managers are DERIVED from the owner account and
     * active property_managers rows (never duplicated). Law enforcement, game
     * warden, emergency and custom contacts come from property_contacts.
     *
     * Every party carries both the raw `phone` and a display-ready
     * `phone_formatted` (+1 (123) 456-7890, see PhoneNumber).
     *
     * Shape:
     * [
     *   'landowner' => ['name','phone','phone_formatted','email']|null,
     *   'managers'  => [['name','role','role_label','phone','phone_formatted','email'], ...],
     *   'contacts'  => [['type','type_label','name','organization','phone','phone_formatted','email','address','notes'], ...],
     * ]
     *
     * The internal `manager_id` (DB 2 property_managers.id) is only included when
     * $includeManagerIds is true — the admin Contacts tab needs it to wire up the
     * Delete action. Lessee-facing callers (member lease page, mobile API) leave it
     * false so the internal grant UUID is never disclosed to hunters. See SEC-042.
     */
    public function getContactDirectory(string $propertyId, bool $includeManagerIds = false): array
    {
        $property = Property::on('property_read')->find($propertyId);

        if (! $property) {
            return ['landowner' => null, 'managers' => [], 'contacts' => []];
        }

        // Managers shown to hunters are opt-in only: an admin must explicitly add a
        // manager as a field contact (is_field_contact) via the Contacts tab.
        $managerRows = PropertyManager::on('property_read')
            ->where('property_id', $propertyId)
            ->whereNull('revoked_at')
            ->whereIn('role', ['co_owner', 'manager', 'operator'])
            ->where('is_field_contact', true)
            ->orderBy('granted_at')
            ->get();

        // Bulk-load every referenced user (owner + managers) with their profile.
        $userIds = $managerRows->pluck('user_id')
            ->push($property->owner_user_id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $users = \App\Models\Identity\User::on('identity')
            ->with('profile')
            ->whereIn('id', $userIds)
            ->get()
            ->keyBy('id');

        $toContact = function (?\App\Models\Identity\User $user): ?array {
            if (! $user) {
                return null;
            }
            return [
                'name'            => $user->profile?->full_name ?: $user->email,
                'phone'           => $user->phone,
                'phone_formatted' => PhoneNumber::format($user->phone),
                'email'           => $user->email,
            ];
        };

        $owner     = $users->get($property->owner_user_id);
        $landowner = $toContact($owner);

        $roleLabels = ['co_owner' => 'Co-Owner', 'manager' => 'Property Manager', 'operator' => 'Operator'];

        $managers = $managerRows->map(function (PropertyManager $m) use ($users, $toContact, $roleLabels, $includeManagerIds) {
            $contact = $toContact($users->get($m->user_id));
            if (! $contact) {
                return null;
            }
            return array_merge($contact, [
                'role'       => $m->role,
                'role_label' => $roleLabels[$m->role] ?? ucfirst($m->role),
            ], $includeManagerIds ? ['manager_id' => $m->id] : []);
        })->filter()->values()->all();

        $contacts = PropertyContact::on('property_read')
            ->where('property_id', $propertyId)
            ->whereNull('deleted_at')
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get()
            ->map(fn (PropertyContact $c) => [
                'type'         => $c->contact_type,
                'type_label'   => $c->displayLabel(),
                'name'            => $c->name,
                'organization'    => $c->organization,
                'phone'           => $c->phone,
                'phone_formatted' => PhoneNumber::format($c->phone),
                'email'           => $c->email,
                'address'         => $c->address,
                'notes'           => $c->notes,
            ])
            ->values()
            ->all();

        return compact('landowner', 'managers', 'contacts');
    }

    /**
     * Active managers who could be promoted to field contacts but aren't yet —
     * options for the member portal "Add Manager Contact" picker.
     *
     * @return list<array{id:string, name:string, role_label:string}>
     */
    public function getEligibleManagerContacts(string $propertyId): array
    {
        $managers = PropertyManager::on('property_read')
            ->where('property_id', $propertyId)
            ->whereNull('revoked_at')
            ->whereIn('role', ['co_owner', 'manager', 'operator'])
            ->where('is_field_contact', false)
            ->orderBy('granted_at')
            ->get();

        if ($managers->isEmpty()) {
            return [];
        }

        $users = \App\Models\Identity\User::on('identity')
            ->with('profile')
            ->whereIn('id', $managers->pluck('user_id')->all())
            ->get()
            ->keyBy('id');

        $roleLabels = ['co_owner' => 'Co-Owner', 'manager' => 'Property Manager', 'operator' => 'Operator'];

        return $managers->map(function (PropertyManager $m) use ($users, $roleLabels) {
            $user = $users->get($m->user_id);
            return [
                'id'         => $m->id,
                'name'       => $user?->profile?->full_name ?: ($user?->email ?? $m->user_id),
                'role_label' => $roleLabels[$m->role] ?? ucfirst($m->role),
            ];
        })->values()->all();
    }

    /** Promote an active manager to a field contact, scoped to its property. */
    public function addManagerContact(string $propertyId, string $managerId): bool
    {
        $manager = PropertyManager::on('property')
            ->where('property_id', $propertyId)
            ->where('id', $managerId)
            ->whereNull('revoked_at')
            ->whereIn('role', ['co_owner', 'manager', 'operator'])
            ->first();

        if (! $manager || $manager->is_field_contact) {
            return false;
        }

        $manager->is_field_contact = true;
        $manager->save();

        return true;
    }

    /** Remove a manager from the field-contact list (the grant itself stays). */
    public function removeManagerContact(string $propertyId, string $managerId): bool
    {
        $manager = PropertyManager::on('property')
            ->where('property_id', $propertyId)
            ->where('id', $managerId)
            ->whereNull('revoked_at')
            ->where('is_field_contact', true)
            ->first();

        if (! $manager) {
            return false;
        }

        $manager->is_field_contact = false;
        $manager->save();

        return true;
    }

    /**
     * Emergency & local contacts (PropertyContact rows) for the editor — includes
     * the row id so the member portal can update/delete them.
     *
     * @return list<array<string,mixed>>
     */
    public function getEditableContacts(string $propertyId): array
    {
        return PropertyContact::on('property_read')
            ->where('property_id', $propertyId)
            ->whereNull('deleted_at')
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get()
            ->map(fn (PropertyContact $c) => [
                'id'           => $c->id,
                'contact_type' => $c->contact_type,
                'label'        => $c->label,
                'name'         => $c->name,
                'organization' => $c->organization,
                'phone'        => $c->phone,
                'email'        => $c->email,
                'address'      => $c->address,
                'notes'        => $c->notes,
            ])
            ->values()
            ->all();
    }

    /** Create an emergency/local contact for a property. */
    public function addContact(string $propertyId, array $data): PropertyContact
    {
        $nextSort = (int) PropertyContact::on('property')
            ->where('property_id', $propertyId)
            ->whereNull('deleted_at')
            ->max('sort_order') + 1;

        return PropertyContact::on('property')->create([
            'property_id'  => $propertyId,
            'contact_type' => $data['contact_type'],
            'label'        => $data['label'] ?? null,
            'name'         => $data['name'] ?? null,
            'organization' => $data['organization'] ?? null,
            'phone'        => $this->cleanPhone($data['phone'] ?? null),
            'email'        => $data['email'] ?? null,
            'address'      => $data['address'] ?? null,
            'notes'        => $data['notes'] ?? null,
            'sort_order'   => $nextSort,
        ]);
    }

    /** Update an emergency/local contact, scoped to its property. */
    public function updateContact(string $propertyId, string $contactId, array $data): bool
    {
        $contact = PropertyContact::on('property')
            ->where('property_id', $propertyId)
            ->where('id', $contactId)
            ->whereNull('deleted_at')
            ->first();

        if (! $contact) {
            return false;
        }

        $contact->update([
            'contact_type' => $data['contact_type'],
            'label'        => $data['label'] ?? null,
            'name'         => $data['name'] ?? null,
            'organization' => $data['organization'] ?? null,
            'phone'        => $this->cleanPhone($data['phone'] ?? null),
            'email'        => $data['email'] ?? null,
            'address'      => $data['address'] ?? null,
            'notes'        => $data['notes'] ?? null,
        ]);

        return true;
    }

    /** Soft-delete an emergency/local contact, scoped to its property. */
    public function deleteContact(string $propertyId, string $contactId): bool
    {
        $contact = PropertyContact::on('property')
            ->where('property_id', $propertyId)
            ->where('id', $contactId)
            ->whereNull('deleted_at')
            ->first();

        if (! $contact) {
            return false;
        }

        // deleted_at is not fillable, so set it directly (mass-assignment no-ops it).
        $contact->deleted_at = now();
        $contact->save();

        return true;
    }

    /** Strip a phone down to its digits for storage; null when empty. */
    private function cleanPhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        return $digits !== '' ? $digits : null;
    }

    /**
     * Live photos for the member portal gallery as plain arrays. The caller maps
     * document_id to a URL — services don't generate routes.
     *
     * @return list<array<string,mixed>>
     */
    public function getPhotosForDisplay(string $propertyId): array
    {
        return PropertyPhoto::on('property_read')
            ->where('property_id', $propertyId)
            ->whereNull('deleted_at')
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get()
            ->map(fn (PropertyPhoto $p) => [
                'id'          => $p->id,
                'document_id' => $p->document_id,
                'caption'     => $p->caption,
                'tags'        => $p->tags ?? [],
                'is_primary'  => (bool) $p->is_primary,
            ])
            ->values()
            ->all();
    }

    /**
     * Live map images plus their markers for the member portal map editor.
     *
     * @return list<array<string,mixed>>
     */
    public function getMapImagesForDisplay(string $propertyId): array
    {
        return PropertyMapImage::on('property_read')
            ->where('property_id', $propertyId)
            ->whereNull('deleted_at')
            ->orderByDesc('is_boundary')
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->with(['markers' => fn ($q) => $q->whereNull('deleted_at')])
            ->get()
            ->map(fn (PropertyMapImage $img) => [
                'id'          => $img->id,
                'document_id' => $img->document_id,
                'description' => $img->description,
                'is_boundary' => (bool) $img->is_boundary,
                'markers'     => $img->markers->map(fn (PropertyMapMarker $m) => [
                    'id'          => $m->id,
                    'label'       => $m->label,
                    'marker_type' => $m->marker_type,
                    'type_label'  => PropertyMapMarker::TYPES[$m->marker_type] ?? 'Marker',
                    'x_percent'   => (float) $m->x_percent,
                    'y_percent'   => (float) $m->y_percent,
                    'color'       => $m->displayColor(),
                    'notes'       => $m->notes,
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    // ─── Record a view ────────────────────────────────────────────────────────

    /**
     * Append a view event. Fire-and-forget — use only from a queued job.
     */
    // ─── Photos ──────────────────────────────────────────────────────────────────

    /**
     * Store an uploaded image via DocumentService and attach it to the
     * property. The first photo on a property automatically becomes primary.
     */
    public function addPhoto(
        string $propertyId,
        UploadedFile $file,
        ?string $caption = null,
        array $tags = [],
        bool $importExif = true,
    ): PropertyPhoto {
        $property = Property::findOrFail($propertyId);

        $document = $this->documentService->storeUploadedFile(
            $file,
            $property->owner_user_id,
            'photo',
        );

        // Photo location: where the picture was taken, straight from EXIF GPS
        // when the camera recorded it; editable manually afterwards. Skipped
        // entirely when the uploader opts out of EXIF import.
        [$latitude, $longitude] = $importExif
            ? \App\Support\ExifGps::extract($file)
            : [null, null];

        $isFirst  = ! PropertyPhoto::where('property_id', $propertyId)->whereNull('deleted_at')->exists();
        $nextSort = (int) PropertyPhoto::where('property_id', $propertyId)
            ->whereNull('deleted_at')
            ->max('sort_order') + 1;

        $photo = PropertyPhoto::create([
            'property_id' => $propertyId,
            'document_id' => $document->id,
            'sort_order'  => $isFirst ? 0 : $nextSort,
            'caption'     => $caption,
            'tags'        => array_values($tags),
            'latitude'    => $latitude,
            'longitude'   => $longitude,
            'is_primary'  => $isFirst,
        ]);

        if ($isFirst) {
            $property->update(['primary_photo_document_id' => $document->id]);
        }

        return $photo;
    }

    public function updatePhotoDetails(
        string $photoId,
        ?string $caption,
        array $tags,
        ?float $latitude = null,
        ?float $longitude = null,
    ): void {
        if ($latitude !== null && ($latitude < -90 || $latitude > 90)) {
            throw new \InvalidArgumentException('Latitude must be between -90 and 90.');
        }
        if ($longitude !== null && ($longitude < -180 || $longitude > 180)) {
            throw new \InvalidArgumentException('Longitude must be between -180 and 180.');
        }

        PropertyPhoto::whereNull('deleted_at')->findOrFail($photoId)->update([
            'caption'   => $caption !== '' ? $caption : null,
            'tags'      => array_values($tags),
            'latitude'  => $latitude,
            'longitude' => $longitude,
        ]);
    }

    /** Make this photo the property's primary (cover) photo. */
    public function setPrimaryPhoto(string $photoId): void
    {
        $photo = PropertyPhoto::whereNull('deleted_at')->findOrFail($photoId);

        DB::connection('property')->transaction(function () use ($photo): void {
            PropertyPhoto::where('property_id', $photo->property_id)
                ->where('id', '!=', $photo->id)
                ->update(['is_primary' => false]);

            $photo->update(['is_primary' => true]);

            Property::where('id', $photo->property_id)
                ->update(['primary_photo_document_id' => $photo->document_id]);
        });
    }

    /**
     * Soft-delete a photo (the storage object is retained 30 days, then
     * purged). If it was the primary photo, the next photo is promoted.
     */
    public function deletePhoto(string $photoId): void
    {
        $photo = PropertyPhoto::whereNull('deleted_at')->findOrFail($photoId);

        // deleted_at is intentionally not in $fillable, so set it directly —
        // $photo->update(['deleted_at' => ...]) would silently drop the key.
        $photo->deleted_at = now();
        $photo->save();

        // Mark the underlying document deleted so the purge job picks it up
        try {
            $this->documentService->softDelete($photo->document_id);
        } catch (\Throwable $e) {
            report($e);
        }

        if ($photo->is_primary) {
            $next = PropertyPhoto::where('property_id', $photo->property_id)
                ->whereNull('deleted_at')
                ->orderBy('sort_order')
                ->orderBy('created_at')
                ->first();

            $next?->update(['is_primary' => true]);

            Property::where('id', $photo->property_id)
                ->update(['primary_photo_document_id' => $next?->document_id]);
        }
    }

    /**
     * Move a photo one position up or down in the gallery. Re-sequences all
     * sort_order values so legacy duplicates (e.g. all 0) can't block moves.
     */
    public function movePhoto(string $photoId, string $direction): void
    {
        if (! in_array($direction, ['up', 'down'], true)) {
            throw new \InvalidArgumentException("Invalid direction '{$direction}'. Must be 'up' or 'down'.");
        }

        $photo = PropertyPhoto::whereNull('deleted_at')->findOrFail($photoId);

        $photos = PropertyPhoto::where('property_id', $photo->property_id)
            ->whereNull('deleted_at')
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get()
            ->values();

        $index = $photos->search(fn (PropertyPhoto $p) => $p->id === $photo->id);
        $swap  = $direction === 'up' ? $index - 1 : $index + 1;

        if ($index === false || $swap < 0 || $swap >= $photos->count()) {
            return;
        }

        $ordered = $photos->all();
        [$ordered[$index], $ordered[$swap]] = [$ordered[$swap], $ordered[$index]];

        DB::connection('property')->transaction(function () use ($ordered): void {
            foreach ($ordered as $i => $p) {
                if ($p->sort_order !== $i) {
                    $p->update(['sort_order' => $i]);
                }
            }
        });
    }

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
