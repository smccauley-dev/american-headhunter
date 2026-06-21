<?php

namespace Tests\Feature\Billing;

use App\Models\Identity\User;
use App\Models\Platform\MembershipPlan;
use App\Services\Billing\PromotionAutoApplyService;
use App\Services\Platform\EntitlementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Trigger-based grant promotions (signup / first listing). Runs against the
 * real platform/billing/identity connections (the service declares those), so
 * no DatabaseTransactions — every row is force-deleted in tearDown and the
 * entitlement cache is invalidated for the test user.
 */
class PromotionAutoApplyServiceTest extends TestCase
{
    /** @var array<int,string> */ private array $periodIds = [];
    /** @var array<int,string> */ private array $userIds = [];

    private MembershipPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->plan = MembershipPlan::on('platform')->where('plan_key', 'hunter_scout')->firstOrFail();
    }

    protected function tearDown(): void
    {
        $billing  = DB::connection('billing');
        $platform = DB::connection('platform');
        $identity = DB::connection('identity');

        if ($this->userIds) {
            $billing->table('promotion_claims')->whereIn('user_id', $this->userIds)->delete();
        }
        if ($this->periodIds) {
            $platform->table('promotional_periods')->whereIn('id', $this->periodIds)->delete();
        }
        if ($this->userIds) {
            $identity->table('user_profiles')->whereIn('user_id', $this->userIds)->delete();
            $identity->table('users')->whereIn('id', $this->userIds)->delete();
        }

        $entitlements = app(EntitlementService::class);
        foreach ($this->userIds as $uid) {
            $entitlements->invalidateForUser($uid);
        }

        parent::tearDown();
    }

    private function service(): PromotionAutoApplyService
    {
        return app(PromotionAutoApplyService::class);
    }

    private function makeUser(string $accountType = 'hunter', ?string $state = null): User
    {
        $id = (string) Str::uuid();
        DB::connection('identity')->table('users')->insert([
            'id'            => $id,
            'email'         => "autoapply-{$id}@test.invalid",
            'password_hash' => 'test-hash',
            'status'        => 'active',
            'account_type'  => $accountType,
        ]);
        DB::connection('identity')->table('user_profiles')->insert([
            'user_id'    => $id,
            'state_code' => $state,
        ]);
        $this->userIds[] = $id;

        return User::on('identity')->find($id);
    }

    /** An active grant-type promotion with the given trigger flag. */
    private function makeGrant(string $flagColumn, array $overrides = []): string
    {
        $id = (string) Str::uuid();
        DB::connection('platform')->table('promotional_periods')->insert(array_merge([
            'id'             => $id,
            'promo_key'      => 'autoapply_' . Str::random(8),
            'display_name'   => 'Auto-apply Grant',
            'promotion_type' => 'tier_grant',
            'status'         => 'active',
            'grants_plan_id' => $this->plan->id,
            $flagColumn      => true,
        ], $overrides));
        $this->periodIds[] = $id;

        return $id;
    }

    private function claimCount(string $userId): int
    {
        return DB::connection('billing')->table('promotion_claims')->where('user_id', $userId)->count();
    }

    // ── signup ──────────────────────────────────────────────────────────────────

    public function test_signup_grant_applies_for_matching_user(): void
    {
        $periodId = $this->makeGrant('auto_apply_on_signup');
        $user     = $this->makeUser('hunter');

        $this->service()->applyForSignup($user);

        $claim = DB::connection('billing')->table('promotion_claims')->where('user_id', $user->id)->first();
        $this->assertNotNull($claim);
        $this->assertSame($periodId, $claim->promotion_period_id);
        $this->assertSame('signup', $claim->trigger_event);
        $this->assertSame($this->plan->id, $claim->granted_plan_id);
    }

    public function test_signup_skips_period_without_grants_plan(): void
    {
        // A percentage discount has no grants_plan_id — nothing to grant at signup.
        $this->makeGrant('auto_apply_on_signup', [
            'promotion_type'      => 'percentage_discount',
            'grants_plan_id'      => null,
            'discount_percentage' => 20,
        ]);
        $user = $this->makeUser('hunter');

        $this->service()->applyForSignup($user);

        $this->assertSame(0, $this->claimCount($user->id));
    }

    public function test_signup_respects_account_type_targeting(): void
    {
        $this->makeGrant('auto_apply_on_signup', ['target_account_types' => '{landowner}']);
        $user = $this->makeUser('hunter');

        $this->service()->applyForSignup($user);

        $this->assertSame(0, $this->claimCount($user->id));
    }

    public function test_signup_respects_state_targeting(): void
    {
        $this->makeGrant('auto_apply_on_signup', ['target_states' => '{TX}']);

        $wrong = $this->makeUser('hunter', 'CA');
        $this->service()->applyForSignup($wrong);
        $this->assertSame(0, $this->claimCount($wrong->id), 'non-TX user is not granted');

        $right = $this->makeUser('hunter', 'TX');
        $this->service()->applyForSignup($right);
        $this->assertSame(1, $this->claimCount($right->id), 'TX user is granted');
    }

    public function test_signup_dedupes_per_user_period(): void
    {
        $this->makeGrant('auto_apply_on_signup');
        $user = $this->makeUser('hunter');

        $this->service()->applyForSignup($user);
        $this->service()->applyForSignup($user);

        $this->assertSame(1, $this->claimCount($user->id), 'a re-run does not grant the same promo twice');
    }

    public function test_signup_respects_claim_limit(): void
    {
        $periodId = $this->makeGrant('auto_apply_on_signup', ['claim_limit' => 1, 'claim_count' => 1]);
        $user     = $this->makeUser('hunter');

        $this->service()->applyForSignup($user);

        $this->assertSame(0, $this->claimCount($user->id), 'an exhausted promo is not granted');
        $this->assertSame(1, (int) DB::connection('platform')->table('promotional_periods')
            ->where('id', $periodId)->value('claim_count'), 'claim_count is not pushed past its limit');
    }

    public function test_signup_skips_inactive_period(): void
    {
        $this->makeGrant('auto_apply_on_signup', ['status' => 'paused']);
        $user = $this->makeUser('hunter');

        $this->service()->applyForSignup($user);

        $this->assertSame(0, $this->claimCount($user->id));
    }

    public function test_successful_grant_increments_claim_count(): void
    {
        $periodId = $this->makeGrant('auto_apply_on_signup', ['claim_count' => 0]);
        $user     = $this->makeUser('hunter');

        $this->service()->applyForSignup($user);

        $this->assertSame(1, (int) DB::connection('platform')->table('promotional_periods')
            ->where('id', $periodId)->value('claim_count'));
    }

    // ── first listing ─────────────────────────────────────────────────────────────

    public function test_first_listing_grant_applies(): void
    {
        $this->makeGrant('auto_apply_on_first_listing');
        $user = $this->makeUser('landowner');

        $this->service()->applyForFirstListing($user);

        $claim = DB::connection('billing')->table('promotion_claims')->where('user_id', $user->id)->first();
        $this->assertNotNull($claim);
        $this->assertSame('first_listing', $claim->trigger_event);
    }

    public function test_signup_trigger_does_not_fire_first_listing_promos(): void
    {
        $this->makeGrant('auto_apply_on_first_listing');
        $user = $this->makeUser('landowner');

        $this->service()->applyForSignup($user);

        $this->assertSame(0, $this->claimCount($user->id), 'a first-listing promo is not granted at signup');
    }

    // ── signup-page preview ─────────────────────────────────────────────────────────

    public function test_preview_returns_offer_for_matching_account_type(): void
    {
        $this->makeGrant('auto_apply_on_signup', [
            'target_account_types' => '{hunter}',
            'pricing_badge_text'   => 'FOUNDING HUNTER',
            'landing_banner_text'  => 'Your first 90 days are on us.',
        ]);

        $preview = $this->service()->previewForSignup('hunter');

        $this->assertNotNull($preview);
        $this->assertSame('FOUNDING HUNTER', $preview['headline']);
        $this->assertSame('Your first 90 days are on us.', $preview['detail']);
    }

    public function test_preview_excludes_state_targeted_promo(): void
    {
        $this->makeGrant('auto_apply_on_signup', [
            'target_states'       => '{TX}',
            'landing_banner_text' => 'STATE-ONLY-BANNER',
        ]);

        $preview = $this->service()->previewForSignup('hunter');

        // State is unknown at page render, so a state-targeted grant is never advertised.
        $this->assertTrue($preview === null || $preview['detail'] !== 'STATE-ONLY-BANNER');
    }
}
