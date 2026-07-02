<?php

namespace Tests\Feature\Member;

use App\Services\Documents\DocumentService;
use App\Services\Wildlife\HarvestMapService;
use App\Services\Wildlife\HarvestService;
use App\Services\Wildlife\SightingService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The member GPS map (Slice 2). The assembler is the security boundary: DB 5
 * has no RLS, so canView (past/present standing on the property, or manager)
 * is the only fence in front of every co-hunter's precise coordinates
 * (SEC-024). Per-record hide_location_from_members must remove a point from
 * everyone's map except its own hunter's.
 */
class HarvestMapTest extends TestCase
{
    private string $lesseeId;

    private string $pastLesseeId;

    private string $strangerId;

    private string $propertyId;

    private string $leaseId;

    private string $pastLeaseId;

    private string $applicationId;

    private string $pastApplicationId;

    /** @var list<string> */
    private array $geoIds = [];

    private HarvestMapService $maps;

    protected function setUp(): void
    {
        parent::setUp();

        $this->maps = app(HarvestMapService::class);

        $this->lesseeId = (string) Str::uuid();
        $this->pastLesseeId = (string) Str::uuid();
        $this->strangerId = (string) Str::uuid();
        $this->propertyId = (string) Str::uuid();
        $this->leaseId = (string) Str::uuid();
        $this->pastLeaseId = (string) Str::uuid();
        $this->applicationId = (string) Str::uuid();
        $this->pastApplicationId = (string) Str::uuid();

        $this->makeUser($this->lesseeId, 'Current', 'Hunter');
        $this->makeUser($this->pastLesseeId, 'Past', 'Hunter');
        $this->makeUser($this->strangerId, 'No', 'Standing');

        $this->makeLease($this->applicationId, $this->leaseId, $this->lesseeId, 'active');
        $this->makeLease($this->pastApplicationId, $this->pastLeaseId, $this->pastLesseeId, 'expired');
    }

    protected function tearDown(): void
    {
        $this->geoIds = array_merge(
            $this->geoIds,
            DB::connection('wildlife')->table('harvest_logs')
                ->whereIn('lease_id', [$this->leaseId, $this->pastLeaseId])
                ->whereNotNull('location_geospatial_id')->pluck('location_geospatial_id')->all(),
            DB::connection('wildlife')->table('wildlife_sightings')
                ->whereIn('lease_id', [$this->leaseId, $this->pastLeaseId])
                ->whereNotNull('location_geospatial_id')->pluck('location_geospatial_id')->all(),
        );
        if ($this->geoIds !== []) {
            DB::connection('geospatial')->table('harvest_locations')->whereIn('id', $this->geoIds)->delete();
        }

        DB::connection('wildlife')->table('harvest_logs')->whereIn('lease_id', [$this->leaseId, $this->pastLeaseId])->delete();
        DB::connection('wildlife')->table('wildlife_sightings')->whereIn('lease_id', [$this->leaseId, $this->pastLeaseId])->delete();

        DB::connection('lease')->table('leases')->whereIn('id', [$this->leaseId, $this->pastLeaseId])->delete();
        DB::connection('lease')->table('lease_applications')->whereIn('id', [$this->applicationId, $this->pastApplicationId])->delete();

        foreach ([$this->lesseeId, $this->pastLesseeId, $this->strangerId] as $uid) {
            DB::connection('identity')->table('profile_photos')->where('user_id', $uid)->delete();
            DB::connection('documents')->table('documents')->where('owner_user_id', $uid)->delete();
            DB::connection('identity')->table('user_profiles')->where('user_id', $uid)->delete();
            DB::connection('identity')->table('users')->where('id', $uid)->delete();
        }

        foreach (['identity', 'lease', 'wildlife', 'geospatial', 'documents'] as $conn) {
            try {
                DB::connection($conn)->disconnect();
            } catch (\Throwable) {
            }
        }

        parent::tearDown();
    }

    private function makeUser(string $id, string $first, string $last): void
    {
        DB::connection('identity')->table('users')->insert([
            'id' => $id,
            'email' => "map-{$id}@example.com",
            'password_hash' => Hash::make('HarvestMap123!'),
            'account_type' => 'hunter',
            'status' => 'active',
            'trust_score' => 75,
            'is_veteran' => false,
            'failed_login_attempts' => 0,
        ]);
        DB::connection('identity')->table('user_profiles')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $id,
            'first_name' => $first,
            'last_name' => $last,
        ]);
    }

    private function makeLease(string $applicationId, string $leaseId, string $lesseeId, string $status): void
    {
        DB::connection('lease')->table('lease_applications')->insert([
            'id' => $applicationId,
            'listing_id' => (string) Str::uuid(),
            'applicant_user_id' => $lesseeId,
            'application_type' => 'individual',
            'status' => 'approved',
        ]);
        DB::connection('lease')->table('leases')->insert([
            'id' => $leaseId,
            'application_id' => $applicationId,
            'property_id' => $this->propertyId,
            'listing_id' => (string) Str::uuid(),
            'lessee_user_id' => $lesseeId,
            'lessor_user_id' => (string) Str::uuid(),
            'status' => $status,
            'start_date' => $status === 'active' ? '2026-06-01' : '2025-10-01',
            'end_date' => $status === 'active' ? '2026-11-30' : '2025-11-30',
            'total_price' => '3000.00',
            'deposit_paid' => '0.00',
        ]);
    }

    private function logHarvest(string $userId, string $leaseId, array $overrides = []): string
    {
        return app(HarvestService::class)->log($userId, $leaseId, array_merge([
            'species_code' => 'whitetail_deer',
            'harvest_date' => '2026-06-15',
            'weapon_type' => 'bow',
            'latitude' => 30.11,
            'longitude' => -98.21,
        ], $overrides))->id;
    }

    // ── The standing gate is the whole fence ─────────────────────────────────────

    public function test_stranger_gets_no_map_and_past_lessee_keeps_theirs(): void
    {
        $this->logHarvest($this->lesseeId, $this->leaseId);

        $this->assertNull($this->maps->forProperty($this->strangerId, $this->propertyId));

        // A PAST lessee (expired lease) still has standing — sees the current
        // hunter's pin.
        $map = $this->maps->forProperty($this->pastLesseeId, $this->propertyId);
        $this->assertNotNull($map);
        $this->assertCount(1, $map['features']);
        $this->assertSame('Whitetail Deer', $map['features'][0]['species']);
        $this->assertSame('Current Hunter', $map['features'][0]['hunter_name']);
        $this->assertFalse($map['features'][0]['is_own']);
    }

    // ── Cross-DB zip: DB 5 record + DB 13 point + DB 1 name ──────────────────────

    public function test_features_carry_real_coordinates_from_db13(): void
    {
        $this->logHarvest($this->lesseeId, $this->leaseId, ['latitude' => 30.5011, 'longitude' => -98.7522]);

        $map = $this->maps->forProperty($this->lesseeId, $this->propertyId);
        $feature = $map['features'][0];

        $this->assertSame('harvest', $feature['type']);
        $this->assertTrue($feature['is_own']);
        $this->assertEqualsWithDelta(30.5011, $feature['lat'], 0.0001);
        $this->assertEqualsWithDelta(-98.7522, $feature['lng'], 0.0001);
    }

    public function test_sightings_appear_alongside_harvests(): void
    {
        $this->logHarvest($this->lesseeId, $this->leaseId);
        app(SightingService::class)->log($this->lesseeId, $this->leaseId, [
            'species_code' => 'turkey',
            'sighting_date' => '2026-06-20',
            'count' => 4,
            'latitude' => 30.12,
            'longitude' => -98.22,
        ]);

        $map = $this->maps->forProperty($this->lesseeId, $this->propertyId);
        $types = collect($map['features'])->pluck('type')->sort()->values()->all();

        $this->assertSame(['harvest', 'sighting'], $types);
        $sighting = collect($map['features'])->firstWhere('type', 'sighting');
        $this->assertSame(4, $sighting['count']);
    }

    // ── Spot privacy: hidden pins are owner-only ─────────────────────────────────

    public function test_hidden_spot_is_removed_for_everyone_but_its_hunter(): void
    {
        $this->logHarvest($this->lesseeId, $this->leaseId, ['hide_location_from_members' => true]);

        // The owner still sees their own hidden pin.
        $own = $this->maps->forProperty($this->lesseeId, $this->propertyId);
        $this->assertCount(1, $own['features']);

        // A co-hunter with standing does not.
        $other = $this->maps->forProperty($this->pastLesseeId, $this->propertyId);
        $this->assertCount(0, $other['features']);
    }

    // ── Records without GPS never reach the map ──────────────────────────────────

    public function test_records_without_gps_are_excluded(): void
    {
        app(HarvestService::class)->log($this->lesseeId, $this->leaseId, [
            'species_code' => 'turkey',
            'harvest_date' => '2026-06-18',
            'weapon_type' => 'shotgun',
            // no latitude/longitude
        ]);

        $map = $this->maps->forProperty($this->lesseeId, $this->propertyId);
        $this->assertCount(0, $map['features']);
    }

    // ── The map's photo route: standing-gated, SEC-061 respected ─────────────────

    public function test_map_photo_route_is_standing_gated_and_respects_sec061(): void
    {
        Storage::fake(config('filesystems.defaults.documents', 'local'));
        Queue::fake();

        $harvestId = $this->logHarvest($this->lesseeId, $this->leaseId);

        $img = imagecreatetruecolor(8, 8);
        ob_start();
        imagejpeg($img);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        $doc = app(HarvestService::class)->attachFieldPhoto(
            $this->lesseeId, $harvestId, UploadedFile::fake()->createWithContent('shot.jpg', $bytes),
        );
        app(DocumentService::class)->markReady($doc->id);

        $url = "/member/harvest-photos/{$doc->id}";

        // A co-hunter with (past) standing may load it; a stranger never learns
        // it exists.
        $this->withSession(['auth.user_id' => $this->pastLesseeId])->get($url)->assertOk();
        $this->withSession(['auth.user_id' => $this->strangerId])->get($url)->assertNotFound();

        // Hiding the spot hides the photo from co-hunters too; the owner keeps it.
        DB::connection('wildlife')->table('harvest_logs')->where('id', $harvestId)
            ->update(['hide_location_from_members' => true]);
        $this->withSession(['auth.user_id' => $this->pastLesseeId])->get($url)->assertNotFound();
        $this->withSession(['auth.user_id' => $this->lesseeId])->get($url)->assertOk();

        // A location-retaining photo (SEC-061 — EXIF GPS kept in the file) is
        // never served to anyone but its owner, even when the spot is visible.
        DB::connection('wildlife')->table('harvest_logs')->where('id', $harvestId)
            ->update(['hide_location_from_members' => false]);
        DB::connection('identity')->table('profile_photos')->where('document_id', $doc->id)
            ->update(['is_location_private' => true]);
        $this->withSession(['auth.user_id' => $this->pastLesseeId])->get($url)->assertNotFound();
        $this->withSession(['auth.user_id' => $this->lesseeId])->get($url)->assertOk();
    }
}
