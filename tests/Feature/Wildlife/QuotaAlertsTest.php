<?php

namespace Tests\Feature\Wildlife;

use App\Jobs\Wildlife\CheckQuotaAlerts;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CheckQuotaAlerts (Phase 6.4) — the daily landowner quota-warning sweep.
 *
 * A quota crossing 75% then 90% of its tags notifies the property owner once
 * per band: alert_threshold_notified records the highest band already sent, so a
 * repeated daily run never re-nags at the same level. The landowner is resolved
 * cross-DB (property_id → DB 2 owner) and the notification is system-authored.
 */
class QuotaAlertsTest extends TestCase
{
    private string $ownerId;

    private string $propertyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ownerId = (string) Str::uuid();
        $this->propertyId = (string) Str::uuid();

        DB::connection('identity')->table('users')->insert([
            'id' => $this->ownerId,
            'email' => "owner-{$this->ownerId}@example.com",
            'password_hash' => Hash::make('QuotaAlerts123!'),
            'account_type' => 'landowner',
            'status' => 'active',
            'trust_score' => 75,
            'is_veteran' => false,
            'failed_login_attempts' => 0,
        ]);
        DB::connection('identity')->table('user_profiles')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $this->ownerId,
            'first_name' => 'Quota',
            'last_name' => 'Owner',
        ]);

        DB::connection('property')->table('properties')->insert([
            'id' => $this->propertyId,
            'owner_user_id' => $this->ownerId,
            'title' => 'Quota Alert Ranch',
            'slug' => 'quota-alert-ranch-'.substr($this->propertyId, 0, 8),
            'status' => 'active',
            'state_code' => 'TX',
            'county' => 'Kerr',
            'total_acres' => '500.00',
        ]);
    }

    protected function tearDown(): void
    {
        DB::connection('wildlife')->table('harvest_quotas')->where('property_id', $this->propertyId)->delete();
        DB::connection('property')->table('properties')->where('id', $this->propertyId)->delete();
        DB::connection('communications')->table('notifications')->where('user_id', $this->ownerId)->delete();
        DB::connection('identity')->table('user_profiles')->where('user_id', $this->ownerId)->delete();
        DB::connection('identity')->table('users')->where('id', $this->ownerId)->delete();

        foreach (['identity', 'property', 'wildlife', 'communications'] as $conn) {
            try {
                DB::connection($conn)->disconnect();
            } catch (\Throwable) {
            }
        }

        parent::tearDown();
    }

    private function seedQuota(int $max, int $current, int $notified = 0): string
    {
        $id = (string) Str::uuid();
        DB::connection('wildlife')->table('harvest_quotas')->insert([
            'id' => $id,
            'property_id' => $this->propertyId,
            'lease_id' => null,
            'species_code' => 'whitetail_deer',
            'season_year' => 2026,
            'max_harvest' => $max,
            'current_harvest' => $current,
            'alert_threshold_notified' => $notified,
        ]);

        return $id;
    }

    private function alerts(): Collection
    {
        return collect(DB::connection('communications')->table('notifications')
            ->where('user_id', $this->ownerId)
            ->where('type', 'wildlife.quota_threshold')
            ->get());
    }

    private function notifiedBand(string $quotaId): int
    {
        return (int) DB::connection('wildlife')->table('harvest_quotas')->where('id', $quotaId)->value('alert_threshold_notified');
    }

    public function test_crossing_75_percent_notifies_the_owner_once(): void
    {
        $quotaId = $this->seedQuota(max: 4, current: 3); // exactly 75%

        CheckQuotaAlerts::dispatchSync();

        $alerts = $this->alerts();
        $this->assertCount(1, $alerts);
        $this->assertSame(75, json_decode($alerts->first()->data, true)['threshold']);
        $this->assertSame(75, $this->notifiedBand($quotaId));

        // A second daily run must not re-nag at the same band.
        CheckQuotaAlerts::dispatchSync();
        $this->assertCount(1, $this->alerts());
    }

    public function test_crossing_90_percent_sends_a_distinct_second_alert(): void
    {
        // Already notified at 75%; the count has since climbed to 90%.
        $quotaId = $this->seedQuota(max: 10, current: 9, notified: 75);

        CheckQuotaAlerts::dispatchSync();

        $alerts = $this->alerts();
        $this->assertCount(1, $alerts);
        $this->assertSame(90, json_decode($alerts->first()->data, true)['threshold']);
        $this->assertSame(90, $this->notifiedBand($quotaId));

        // 90 is the top band — no further alerts.
        CheckQuotaAlerts::dispatchSync();
        $this->assertCount(1, $this->alerts());
    }

    public function test_below_75_percent_is_not_alerted(): void
    {
        $quotaId = $this->seedQuota(max: 10, current: 5); // 50%

        CheckQuotaAlerts::dispatchSync();

        $this->assertCount(0, $this->alerts());
        $this->assertSame(0, $this->notifiedBand($quotaId));
    }
}
