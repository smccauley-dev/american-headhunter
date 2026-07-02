<?php

namespace Tests\Feature\Member;

use App\Services\Wildlife\CwdService;
use App\Services\Wildlife\HarvestService;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Full harvest edit + delete (Slice 3). The interesting invariants are the
 * accounting ones: a species/season change atomically claims the NEW quota tag
 * before releasing the old (a full target bucket is a 409 that leaves the
 * original untouched); a new location re-runs the CWD gate and — because
 * DB 13 harvest_locations is immutable — writes a NEW point and repoints
 * location_geospatial_id; delete releases the tag so the season count stays
 * honest. Writes are owner-only: standing lets you read, not edit.
 */
class HarvestEditTest extends TestCase
{
    private string $lesseeId;

    private string $strangerId;

    private string $propertyId;

    private string $leaseId;

    private string $applicationId;

    /** @var list<string> */
    private array $geoIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        $this->lesseeId = (string) Str::uuid();
        $this->strangerId = (string) Str::uuid();
        $this->propertyId = (string) Str::uuid();
        $this->leaseId = (string) Str::uuid();
        $this->applicationId = (string) Str::uuid();

        $this->makeUser($this->lesseeId, 'lessee');
        $this->makeUser($this->strangerId, 'stranger');

        DB::connection('lease')->table('lease_applications')->insert([
            'id' => $this->applicationId,
            'listing_id' => (string) Str::uuid(),
            'applicant_user_id' => $this->lesseeId,
            'application_type' => 'individual',
            'status' => 'approved',
        ]);
        DB::connection('lease')->table('leases')->insert([
            'id' => $this->leaseId,
            'application_id' => $this->applicationId,
            'property_id' => $this->propertyId,
            'listing_id' => (string) Str::uuid(),
            'lessee_user_id' => $this->lesseeId,
            'lessor_user_id' => (string) Str::uuid(),
            'status' => 'active',
            'start_date' => '2026-10-01',
            'end_date' => '2026-11-30',
            'total_price' => '3000.00',
            'deposit_paid' => '0.00',
        ]);
    }

    protected function tearDown(): void
    {
        $this->geoIds = array_merge($this->geoIds, DB::connection('wildlife')->table('harvest_logs')
            ->where('lease_id', $this->leaseId)->whereNotNull('location_geospatial_id')
            ->pluck('location_geospatial_id')->all());
        if ($this->geoIds !== []) {
            DB::connection('geospatial')->table('harvest_locations')->whereIn('id', $this->geoIds)->delete();
        }

        DB::connection('wildlife')->table('harvest_logs')->where('lease_id', $this->leaseId)->delete();
        DB::connection('wildlife')->table('harvest_quotas')->where('property_id', $this->propertyId)->delete();

        DB::connection('lease')->table('leases')->where('id', $this->leaseId)->delete();
        DB::connection('lease')->table('lease_applications')->where('id', $this->applicationId)->delete();

        foreach ([$this->lesseeId, $this->strangerId] as $uid) {
            DB::connection('identity')->table('profile_photos')->where('user_id', $uid)->delete();
            DB::connection('documents')->table('documents')->where('owner_user_id', $uid)->delete();
            DB::connection('identity')->table('user_profiles')->where('user_id', $uid)->delete();
            DB::connection('identity')->table('users')->where('id', $uid)->delete();
        }

        foreach (['identity', 'lease', 'wildlife', 'documents', 'geospatial'] as $conn) {
            try {
                DB::connection($conn)->disconnect();
            } catch (\Throwable) {
            }
        }

        parent::tearDown();
    }

    private function makeUser(string $id, string $label): void
    {
        DB::connection('identity')->table('users')->insert([
            'id' => $id,
            'email' => "{$label}-{$id}@example.com",
            'password_hash' => Hash::make('HarvestEdit123!'),
            'account_type' => 'hunter',
            'status' => 'active',
            'trust_score' => 75,
            'is_veteran' => false,
            'failed_login_attempts' => 0,
        ]);
        DB::connection('identity')->table('user_profiles')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $id,
            'first_name' => ucfirst($label),
            'last_name' => 'Tester',
        ]);
    }

    private function seedHarvest(array $overrides = []): string
    {
        return app(HarvestService::class)->log($this->lesseeId, $this->leaseId, array_merge([
            'species_code' => 'whitetail_deer',
            'harvest_date' => '2026-06-15',
            'weapon_type' => 'bow',
        ], $overrides))->id;
    }

    private function seedQuota(string $species, int $max, int $current = 0): void
    {
        DB::connection('wildlife')->table('harvest_quotas')->insert([
            'id' => (string) Str::uuid(),
            'property_id' => $this->propertyId,
            'lease_id' => $this->leaseId,
            'species_code' => $species,
            'season_year' => 2026,
            'max_harvest' => $max,
            'current_harvest' => $current,
        ]);
    }

    private function quotaCount(string $species): int
    {
        return (int) DB::connection('wildlife')->table('harvest_quotas')
            ->where('property_id', $this->propertyId)
            ->where('species_code', $species)
            ->value('current_harvest');
    }

    /** @return array<string,mixed> */
    private function updatePayload(array $overrides = []): array
    {
        return array_merge([
            'species_code' => 'whitetail_deer',
            'weapon_type' => 'bow',
            'harvest_date' => '2026-06-15',
        ], $overrides);
    }

    private function harvestRow(string $id): object
    {
        return DB::connection('wildlife')->table('harvest_logs')->where('id', $id)->first();
    }

    // ── Plain field edit ─────────────────────────────────────────────────────────

    public function test_owner_can_edit_fields(): void
    {
        $id = $this->seedHarvest();

        $this->withSession(['auth.user_id' => $this->lesseeId])
            ->put("/member/harvest/{$id}", $this->updatePayload([
                'weapon_type' => 'rifle',
                'notes' => 'Corrected the weapon.',
                'is_public' => true,
            ]))
            ->assertRedirect('/member/harvest')
            ->assertSessionHas('success');

        $row = $this->harvestRow($id);
        $this->assertSame('rifle', $row->weapon_type);
        $this->assertSame('Corrected the weapon.', $row->notes);
        $this->assertTrue((bool) $row->is_public);
    }

    // ── Quota re-accounting ──────────────────────────────────────────────────────

    public function test_species_change_moves_the_quota_tag(): void
    {
        $this->seedQuota('whitetail_deer', max: 2);
        $this->seedQuota('turkey', max: 1);

        $id = $this->seedHarvest(); // consumes a whitetail tag
        $this->assertSame(1, $this->quotaCount('whitetail_deer'));

        $this->withSession(['auth.user_id' => $this->lesseeId])
            ->put("/member/harvest/{$id}", $this->updatePayload(['species_code' => 'turkey']))
            ->assertRedirect('/member/harvest');

        $this->assertSame('turkey', $this->harvestRow($id)->species_code);
        $this->assertSame(0, $this->quotaCount('whitetail_deer'));
        $this->assertSame(1, $this->quotaCount('turkey'));
    }

    public function test_full_target_quota_is_a_flash_error_and_keeps_the_old_tag(): void
    {
        $this->seedQuota('whitetail_deer', max: 2);
        $this->seedQuota('turkey', max: 1, current: 1); // already full

        $id = $this->seedHarvest();

        $this->withSession(['auth.user_id' => $this->lesseeId])
            ->put("/member/harvest/{$id}", $this->updatePayload(['species_code' => 'turkey']))
            ->assertRedirect()
            ->assertSessionHas('error');

        // Nothing moved: record unchanged, both buckets intact.
        $this->assertSame('whitetail_deer', $this->harvestRow($id)->species_code);
        $this->assertSame(1, $this->quotaCount('whitetail_deer'));
        $this->assertSame(1, $this->quotaCount('turkey'));
    }

    // ── Location: immutable DB 13 point is replaced, never updated ───────────────

    public function test_new_location_writes_a_new_geospatial_point(): void
    {
        $id = $this->seedHarvest(['latitude' => 30.10, 'longitude' => -98.20]);
        $originalGeoId = $this->harvestRow($id)->location_geospatial_id;
        $this->assertNotNull($originalGeoId);
        $this->geoIds[] = $originalGeoId;

        $this->withSession(['auth.user_id' => $this->lesseeId])
            ->put("/member/harvest/{$id}", $this->updatePayload([
                'latitude' => 30.55, 'longitude' => -98.65, 'gps_accuracy_m' => 8,
            ]))
            ->assertRedirect('/member/harvest');

        $newGeoId = $this->harvestRow($id)->location_geospatial_id;
        $this->assertNotSame($originalGeoId, $newGeoId);

        // Both points exist — the original row was never touched (immutable).
        $count = DB::connection('geospatial')->table('harvest_locations')
            ->whereIn('id', [$originalGeoId, $newGeoId])->count();
        $this->assertSame(2, $count);
    }

    public function test_clear_location_detaches_the_point(): void
    {
        $id = $this->seedHarvest(['latitude' => 30.10, 'longitude' => -98.20]);
        $this->geoIds[] = $this->harvestRow($id)->location_geospatial_id;

        $this->withSession(['auth.user_id' => $this->lesseeId])
            ->put("/member/harvest/{$id}", $this->updatePayload(['clear_location' => true]))
            ->assertRedirect('/member/harvest');

        $this->assertNull($this->harvestRow($id)->location_geospatial_id);
    }

    public function test_new_location_in_cwd_zone_requires_acknowledgment(): void
    {
        $id = $this->seedHarvest();

        $cwd = Mockery::mock(CwdService::class)->makePartial();
        $cwd->shouldReceive('zonesRequiringAcknowledgment')
            ->andReturn(collect([(object) ['id' => (string) Str::uuid(), 'zone_name' => 'Test Zone']]));
        $this->app->instance(CwdService::class, $cwd);

        $this->withSession(['auth.user_id' => $this->lesseeId])
            ->put("/member/harvest/{$id}", $this->updatePayload([
                'latitude' => 30.55, 'longitude' => -98.65, 'species_code' => 'mule_deer',
            ]))
            ->assertRedirect()
            ->assertSessionHasErrors(['cwd_acknowledged']);

        // Gate fired before any side effect: species unchanged, no new point.
        $row = $this->harvestRow($id);
        $this->assertSame('whitetail_deer', $row->species_code);
        $this->assertNull($row->location_geospatial_id);
    }

    // ── Write authorization: owner-only, stranger never learns it exists ─────────

    public function test_stranger_gets_404_and_standing_alone_cannot_edit(): void
    {
        $id = $this->seedHarvest();

        $this->withSession(['auth.user_id' => $this->strangerId])
            ->put("/member/harvest/{$id}", $this->updatePayload(['notes' => 'nope']))
            ->assertStatus(404);

        // A co-hunter with standing may READ the record but never edit it: seed a
        // row authored by someone else on the same lease and try to edit it.
        $otherId = (string) Str::uuid();
        DB::connection('wildlife')->table('harvest_logs')->insert([
            'id' => $otherId,
            'lease_id' => $this->leaseId,
            'user_id' => (string) Str::uuid(),
            'property_id' => $this->propertyId,
            'species_code' => 'turkey',
            'harvest_date' => '2026-06-10',
            'weapon_type' => 'shotgun',
            'field_photos' => '[]',
        ]);

        $this->withSession(['auth.user_id' => $this->lesseeId])
            ->put("/member/harvest/{$otherId}", $this->updatePayload(['species_code' => 'turkey', 'notes' => 'mine now']))
            ->assertStatus(403);

        $this->assertNull($this->harvestRow($otherId)->notes);
    }

    // ── Delete: quota released, soft-deleted ─────────────────────────────────────

    public function test_delete_soft_deletes_and_releases_the_quota_tag(): void
    {
        $this->seedQuota('whitetail_deer', max: 2);
        $id = $this->seedHarvest();
        $this->assertSame(1, $this->quotaCount('whitetail_deer'));

        $this->withSession(['auth.user_id' => $this->lesseeId])
            ->delete("/member/harvest/{$id}")
            ->assertRedirect('/member/harvest')
            ->assertSessionHas('success');

        $this->assertNotNull($this->harvestRow($id)->deleted_at);
        $this->assertSame(0, $this->quotaCount('whitetail_deer'));

        // Gone from the member's list and no longer editable.
        $this->withSession(['auth.user_id' => $this->lesseeId])
            ->put("/member/harvest/{$id}", $this->updatePayload())
            ->assertStatus(404);
    }

    // ── Photo add/remove on edit ─────────────────────────────────────────────────

    public function test_edit_can_remove_an_attached_photo(): void
    {
        Storage::fake(config('filesystems.defaults.documents', 'local'));
        Queue::fake();

        $id = $this->seedHarvest();

        $img = imagecreatetruecolor(8, 8);
        ob_start();
        imagejpeg($img);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        $doc = app(HarvestService::class)->attachFieldPhoto(
            $this->lesseeId, $id, UploadedFile::fake()->createWithContent('shot.jpg', $bytes),
        );

        $this->withSession(['auth.user_id' => $this->lesseeId])
            ->put("/member/harvest/{$id}", $this->updatePayload(['remove_photo_ids' => [$doc->id]]))
            ->assertRedirect('/member/harvest');

        $this->assertSame([], json_decode((string) $this->harvestRow($id)->field_photos, true));

        // The document and its gallery mirror are both soft-deleted.
        $this->assertNotNull(DB::connection('documents')->table('documents')->where('id', $doc->id)->value('deleted_at'));
        $this->assertNotNull(DB::connection('identity')->table('profile_photos')->where('document_id', $doc->id)->value('deleted_at'));
    }
}
