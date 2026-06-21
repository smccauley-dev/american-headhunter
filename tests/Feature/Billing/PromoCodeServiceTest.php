<?php

namespace Tests\Feature\Billing;

use App\Models\Billing\PromoCode;
use App\Models\Identity\User;
use App\Models\Platform\MembershipPlan;
use App\Services\Billing\PromoCodeService;
use App\Services\Platform\EntitlementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Promo-code redemption rules for membership checkout (PromoCodeService).
 *
 * Runs against the real `billing` / `platform` / `identity` connections (the
 * service declares those explicitly), so no DatabaseTransactions — every row
 * created here is force-deleted in tearDown and the entitlement cache is
 * invalidated for the test user.
 */
class PromoCodeServiceTest extends TestCase
{
    /** @var array<int,string> */ private array $codeIds = [];
    /** @var array<int,string> */ private array $periodIds = [];
    /** @var array<int,string> */ private array $linkIds = [];
    /** @var array<int,string> */ private array $claimIds = [];
    /** @var array<int,string> */ private array $userIds = [];

    private MembershipPlan $scout;   // the plan we validate against
    private MembershipPlan $pro;     // a different plan (for the restriction test)

    protected function setUp(): void
    {
        parent::setUp();
        $this->scout = MembershipPlan::on('platform')->where('plan_key', 'hunter_scout')->firstOrFail();
        $this->pro   = MembershipPlan::on('platform')->where('plan_key', 'hunter_pro')->firstOrFail();
    }

    protected function tearDown(): void
    {
        $billing  = DB::connection('billing');
        $platform = DB::connection('platform');
        $identity = DB::connection('identity');

        if ($this->claimIds)  { $billing->table('promotion_claims')->whereIn('id', $this->claimIds)->delete(); }
        if ($this->codeIds)   { $billing->table('promo_codes')->whereIn('id', $this->codeIds)->delete(); }
        if ($this->linkIds)   { $platform->table('plan_promo_codes')->whereIn('id', $this->linkIds)->delete(); }
        if ($this->periodIds) { $platform->table('promotional_periods')->whereIn('id', $this->periodIds)->delete(); }
        if ($this->userIds)   { $identity->table('users')->whereIn('id', $this->userIds)->delete(); }

        $entitlements = app(EntitlementService::class);
        foreach ($this->userIds as $uid) {
            $entitlements->invalidateForUser($uid);
        }

        try { $billing->disconnect(); } catch (\Throwable) {}
        try { $platform->disconnect(); } catch (\Throwable) {}
        try { $identity->disconnect(); } catch (\Throwable) {}
        parent::tearDown();
    }

    private function service(): PromoCodeService
    {
        return app(PromoCodeService::class);
    }

    private function makeUser(string $accountType = 'hunter'): User
    {
        $id = (string) Str::uuid();
        DB::connection('identity')->table('users')->insert([
            'id'            => $id,
            'email'         => "promo-{$id}@test.invalid",
            'password_hash' => 'test-hash',
            'status'        => 'active',
            'account_type'  => $accountType,
        ]);
        $this->userIds[] = $id;

        return User::on('identity')->find($id);
    }

    /** An active, discounting promotional period (percentage). */
    private function makePeriod(array $overrides = []): string
    {
        $id = (string) Str::uuid();
        DB::connection('platform')->table('promotional_periods')->insert(array_merge([
            'id'                  => $id,
            'promo_key'           => 'test_promo_' . Str::random(8),
            'display_name'        => 'Test Promo',
            'promotion_type'      => 'percentage_discount',
            'status'              => 'active',
            'discount_percentage' => 20,
        ], $overrides));
        $this->periodIds[] = $id;

        return $id;
    }

    /** A promo code attached to a period. */
    private function makeCode(string $periodId, array $overrides = []): PromoCode
    {
        $code = PromoCode::on('billing')->create(array_merge([
            'promotional_period_id' => $periodId,
            'code'                  => 'SAVE' . strtoupper(Str::random(6)),
            'is_active'             => true,
        ], $overrides));
        $this->codeIds[] = $code->id;

        return $code;
    }

    /** Link a code to a plan (restriction; optionally shown on the card). */
    private function linkCodeToPlan(PromoCode $code, MembershipPlan $plan, bool $show = false): void
    {
        $id = (string) Str::uuid();
        DB::connection('platform')->table('plan_promo_codes')->insert([
            'id'                   => $id,
            'plan_id'              => $plan->id,
            'promo_code_id'        => $code->id,
            'show_on_pricing_card' => $show,
        ]);
        $this->linkIds[] = $id;
    }

    // ── validateForPlan ─────────────────────────────────────────────────────────

    public function test_valid_unlinked_code_passes_on_any_plan(): void
    {
        $code = $this->makeCode($this->makePeriod());
        $user = $this->makeUser('hunter');

        $result = $this->service()->validateForPlan($code->code, $this->scout, $user);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame($code->id, $result['promo_code']->id);
        $this->assertSame($code->promotional_period_id, $result['period']->id);
    }

    public function test_validation_is_case_insensitive(): void
    {
        $code = $this->makeCode($this->makePeriod());
        $user = $this->makeUser('hunter');

        $result = $this->service()->validateForPlan(strtolower($code->code), $this->scout, $user);

        $this->assertArrayNotHasKey('error', $result);
    }

    public function test_inactive_code_rejected(): void
    {
        $code = $this->makeCode($this->makePeriod(), ['is_active' => false]);
        $user = $this->makeUser('hunter');

        $this->assertArrayHasKey('error', $this->service()->validateForPlan($code->code, $this->scout, $user));
    }

    public function test_expired_code_rejected(): void
    {
        $code = $this->makeCode($this->makePeriod(), ['expires_at' => now()->subDay()]);
        $user = $this->makeUser('hunter');

        $this->assertArrayHasKey('error', $this->service()->validateForPlan($code->code, $this->scout, $user));
    }

    public function test_future_code_rejected(): void
    {
        $code = $this->makeCode($this->makePeriod(), ['starts_at' => now()->addDay()]);
        $user = $this->makeUser('hunter');

        $this->assertArrayHasKey('error', $this->service()->validateForPlan($code->code, $this->scout, $user));
    }

    public function test_exhausted_code_rejected(): void
    {
        $code = $this->makeCode($this->makePeriod(), ['max_redemptions' => 5, 'redemption_count' => 5]);
        $user = $this->makeUser('hunter');

        $this->assertArrayHasKey('error', $this->service()->validateForPlan($code->code, $this->scout, $user));
    }

    public function test_inactive_period_rejected(): void
    {
        $code = $this->makeCode($this->makePeriod(['status' => 'paused']));
        $user = $this->makeUser('hunter');

        $this->assertArrayHasKey('error', $this->service()->validateForPlan($code->code, $this->scout, $user));
    }

    public function test_account_type_targeting_rejected_for_wrong_type(): void
    {
        $code = $this->makeCode($this->makePeriod(['target_account_types' => '{landowner}']));
        $user = $this->makeUser('hunter');

        $this->assertArrayHasKey('error', $this->service()->validateForPlan($code->code, $this->scout, $user));
    }

    public function test_account_type_targeting_accepts_matching_type(): void
    {
        $code = $this->makeCode($this->makePeriod(['target_account_types' => '{hunter}']));
        $user = $this->makeUser('hunter');

        $this->assertArrayNotHasKey('error', $this->service()->validateForPlan($code->code, $this->scout, $user));
    }

    public function test_per_user_limit_rejects_after_prior_claim(): void
    {
        $period = $this->makePeriod();
        $code   = $this->makeCode($period); // per_user_limit defaults to 1
        $user   = $this->makeUser('hunter');

        // A prior claim of this code by this user exhausts the per-user allowance.
        $claimId = (string) Str::uuid();
        DB::connection('billing')->table('promotion_claims')->insert([
            'id'                  => $claimId,
            'user_id'             => $user->id,
            'promotion_period_id' => $period,
            'status'              => 'active',
            'trigger_event'       => 'promo_code',
            'promo_code_used'     => $code->code,
        ]);
        $this->claimIds[] = $claimId;

        $this->assertArrayHasKey('error', $this->service()->validateForPlan($code->code, $this->scout, $user));
    }

    // ── restriction (plan_promo_codes) ──────────────────────────────────────────

    public function test_linked_code_rejected_on_unlinked_plan(): void
    {
        $code = $this->makeCode($this->makePeriod());
        $user = $this->makeUser('hunter');
        $this->linkCodeToPlan($code, $this->scout);

        // Linked to Scout only — must be rejected on Pro.
        $this->assertArrayHasKey('error', $this->service()->validateForPlan($code->code, $this->pro, $user));
    }

    public function test_linked_code_accepted_on_linked_plan(): void
    {
        $code = $this->makeCode($this->makePeriod());
        $user = $this->makeUser('hunter');
        $this->linkCodeToPlan($code, $this->scout);

        $this->assertArrayNotHasKey('error', $this->service()->validateForPlan($code->code, $this->scout, $user));
    }

    // ── autoApplyForPlan ────────────────────────────────────────────────────────

    public function test_auto_apply_picks_first_shown_valid_code(): void
    {
        $code = $this->makeCode($this->makePeriod());
        $user = $this->makeUser('hunter');
        $this->linkCodeToPlan($code, $this->scout, show: true);

        $picked = $this->service()->autoApplyForPlan($this->scout, $user);

        $this->assertNotNull($picked);
        $this->assertSame($code->id, $picked->id);
    }

    public function test_auto_apply_ignores_codes_not_shown_on_card(): void
    {
        $code = $this->makeCode($this->makePeriod());
        $user = $this->makeUser('hunter');
        $this->linkCodeToPlan($code, $this->scout, show: false);

        $this->assertNull($this->service()->autoApplyForPlan($this->scout, $user));
    }

    public function test_auto_apply_skips_invalid_shown_code(): void
    {
        $code = $this->makeCode($this->makePeriod(), ['expires_at' => now()->subDay()]);
        $user = $this->makeUser('hunter');
        $this->linkCodeToPlan($code, $this->scout, show: true);

        $this->assertNull($this->service()->autoApplyForPlan($this->scout, $user));
    }

    // ── recordRedemption ────────────────────────────────────────────────────────

    public function test_record_redemption_increments_count_and_creates_claim(): void
    {
        $period = $this->makePeriod();
        $code   = $this->makeCode($period, ['max_redemptions' => 10, 'redemption_count' => 0]);
        $user   = $this->makeUser('hunter');

        $this->service()->recordRedemption($user, $code);

        $this->assertSame(1, (int) $code->fresh()->redemption_count, 'redemption_count is incremented');

        $claim = DB::connection('billing')->table('promotion_claims')
            ->where('user_id', $user->id)
            ->where('promo_code_used', $code->code)
            ->first();
        $this->assertNotNull($claim, 'a promotion claim is authored for the redemption');
        $this->assertSame('promo_code', $claim->trigger_event);
        $this->claimIds[] = $claim->id;
    }

    public function test_record_redemption_does_not_exceed_max(): void
    {
        $period = $this->makePeriod();
        $code   = $this->makeCode($period, ['max_redemptions' => 3, 'redemption_count' => 3]);
        $user   = $this->makeUser('hunter');

        $this->service()->recordRedemption($user, $code);

        $this->assertSame(3, (int) $code->fresh()->redemption_count, 'an exhausted code is not incremented past its max');
        $this->assertSame(0, DB::connection('billing')->table('promotion_claims')
            ->where('user_id', $user->id)->where('promo_code_used', $code->code)->count(),
            'no claim is authored when the increment is refused');
    }
}
