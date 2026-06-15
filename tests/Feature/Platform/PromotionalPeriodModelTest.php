<?php

namespace Tests\Feature\Platform;

use App\Models\Platform\PromotionalPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Exercises PromotionalPeriod against the real `platform` connection — the
 * model was previously out of sync with the table (wrong column names), so
 * these assert it maps the actual schema, including the TEXT[] cast.
 *
 * No DatabaseTransactions (updated_at trigger uses NOW()); rows created here are
 * force-deleted in tearDown.
 */
class PromotionalPeriodModelTest extends TestCase
{
    /** @var array<int,string> */
    private array $promoIds = [];

    protected function tearDown(): void
    {
        if ($this->promoIds) {
            DB::connection('platform')->table('promotional_periods')->whereIn('id', $this->promoIds)->delete();
        }
        try { DB::connection('platform')->disconnect(); } catch (\Throwable) {}
        parent::tearDown();
    }

    /** Create a promo with an explicit id (id is not mass-assignable). */
    private function make(array $attrs): PromotionalPeriod
    {
        $promo = new PromotionalPeriod($attrs);
        $promo->id = (string) Str::uuid();
        $promo->save();
        $this->promoIds[] = $promo->id;

        return $promo;
    }

    public function test_text_array_columns_round_trip_through_postgres(): void
    {
        $promo = $this->make([
            'promo_key'            => 'test_array_' . Str::random(8),
            'display_name'         => 'Array Round Trip',
            'promotion_type'       => 'tier_grant',
            'status'               => 'draft',
            'target_account_types' => ['landowner', 'hunter'],
            'target_states'        => ['AL', 'GA', 'TN'],
        ]);

        $fresh = PromotionalPeriod::on('platform')->find($promo->id);

        $this->assertSame(['landowner', 'hunter'], $fresh->target_account_types);
        $this->assertSame(['AL', 'GA', 'TN'], $fresh->target_states);
    }

    public function test_seeded_founding_landowner_is_active_and_grants_ranch(): void
    {
        $promo = PromotionalPeriod::on('platform')->where('promo_key', 'founding_landowner_2026')->first();

        $this->assertNotNull($promo, 'founding_landowner_2026 should be seeded');
        $this->assertTrue($promo->isActive());
        $this->assertSame(['landowner'], $promo->target_account_types);
        $this->assertSame('landowner_ranch', $promo->grantsPlan->plan_key);
    }

    public function test_is_active_false_when_claim_limit_reached(): void
    {
        $promo = $this->make([
            'promo_key'      => 'test_exhausted_' . Str::random(8),
            'display_name'   => 'Exhausted',
            'promotion_type' => 'tier_grant',
            'status'         => 'active',
            'claim_limit'    => 5,
            'claim_count'    => 5,
        ]);

        $this->assertFalse($promo->isActive());
    }

    public function test_is_active_false_when_window_has_ended(): void
    {
        $promo = $this->make([
            'promo_key'      => 'test_ended_' . Str::random(8),
            'display_name'   => 'Ended',
            'promotion_type' => 'tier_grant',
            'status'         => 'active',
            'starts_at'      => now()->subDays(30),
            'ends_at'        => now()->subDay(),
        ]);

        $this->assertFalse($promo->isActive());
    }
}
