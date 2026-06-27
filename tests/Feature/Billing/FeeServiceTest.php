<?php

namespace Tests\Feature\Billing;

use App\Models\Billing\FeeSchedule;
use App\Services\Billing\FeeService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 5.5 (Slice 1.5) FeeService — processing-fee surcharge resolution.
 *
 * Runs against the real `billing` connection (tests run as the schema owner, which
 * bypasses RLS, so the system-authored fee_schedules rows can be seeded directly).
 * Rows are tagged with a sentinel description and hard-deleted in tearDown. The
 * resolver caches in Valkey, so each test flushes the cache after seeding.
 */
class FeeServiceTest extends TestCase
{
    private const TAG = '__feeservice_test__';

    private FeeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(FeeService::class);
        $this->cleanup();
        $this->service->flushCache();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        $this->service->flushCache();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        DB::connection('billing')->table('fee_schedules')->where('description', self::TAG)->delete();
    }

    private function seedRule(array $attrs): FeeSchedule
    {
        return FeeSchedule::create(array_merge([
            'payer'       => 'customer',
            'description' => self::TAG,
            'is_active'   => true,
        ], $attrs));
    }

    public function test_resolves_percent_plus_flat(): void
    {
        $this->seedRule([
            'transaction_category' => 'lease',
            'state_code'           => null,
            'pct'                  => 2.9,
            'flat_cents'           => 30,
        ]);
        $this->service->flushCache();

        $fee = $this->service->processingFee('lease', null, 10_000);

        // round(10000 * 2.9%) = 290, + 30 flat = 320
        $this->assertSame(320, $fee['fee_cents']);
        $this->assertSame(2.9, $fee['pct']);
        $this->assertSame(30, $fee['flat_cents']);
        $this->assertSame('customer', $fee['payer']);
    }

    public function test_exact_state_beats_all_states_rule(): void
    {
        $this->seedRule(['transaction_category' => 'lease', 'state_code' => null, 'pct' => 2.9, 'flat_cents' => 30]);
        $this->seedRule(['transaction_category' => 'lease', 'state_code' => 'NC', 'pct' => 1.0, 'flat_cents' => 0]);
        $this->service->flushCache();

        // NC matches the specific rule: round(10000 * 1%) = 100.
        $this->assertSame(100, $this->service->processingFee('lease', 'NC', 10_000)['fee_cents']);

        // TX has no specific rule → falls back to all-states: 290 + 30 = 320.
        $this->assertSame(320, $this->service->processingFee('lease', 'TX', 10_000)['fee_cents']);
    }

    public function test_state_is_case_insensitive(): void
    {
        $this->seedRule(['transaction_category' => 'auction', 'state_code' => 'NC', 'pct' => 0.0, 'flat_cents' => 250]);
        $this->service->flushCache();

        $this->assertSame(250, $this->service->processingFee('auction', 'nc', 5_000)['fee_cents']);
    }

    public function test_no_rule_returns_zero_fee(): void
    {
        $fee = $this->service->processingFee('marketplace', null, 5_000);

        $this->assertSame(0, $fee['fee_cents']);
        $this->assertNull($fee['schedule_id']);
    }

    public function test_inactive_and_expired_rules_are_ignored(): void
    {
        $this->seedRule(['transaction_category' => 'outfitter_booking', 'state_code' => null, 'pct' => 5.0, 'is_active' => false]);
        $this->seedRule(['transaction_category' => 'outfitter_booking', 'state_code' => 'TX', 'pct' => 5.0, 'effective_to' => now()->subDay()]);
        $this->service->flushCache();

        $this->assertSame(0, $this->service->processingFee('outfitter_booking', 'TX', 10_000)['fee_cents']);
    }
}
