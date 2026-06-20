<?php

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * After signup the account is `pending_verification`, and the controller logs the
 * user in (session) and redirects them to the "check your email" notice. That
 * notice route is guarded by `auth.session:allow-pending`; this test pins the two
 * halves of that contract:
 *
 *   - a pending_verification user CAN reach the notice (otherwise they get
 *     bounced to /login right after signup and never see it), and
 *   - a pending_verification user still CANNOT reach active-only areas (/member),
 *     so the lighter check is not a backdoor.
 *
 * Fixtures are committed (not wrapped in a transaction): the notice route runs
 * under `db.system`, which reconnects the identity connection as ah_system and so
 * would not see rows still pending in a test-held transaction. They are removed
 * manually in tearDown.
 */
class VerifyEmailAccessTest extends TestCase
{
    private string $pendingUserId;
    private string $activeUserId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pendingUserId = (string) Str::uuid();
        $this->activeUserId  = (string) Str::uuid();

        DB::connection('identity')->table('users')->insert([
            [
                'id' => $this->pendingUserId, 'email' => "pending-{$this->pendingUserId}@test.invalid",
                'password_hash' => 'test-hash', 'status' => 'pending_verification', 'account_type' => 'hunter',
            ],
            [
                'id' => $this->activeUserId, 'email' => "active-{$this->activeUserId}@test.invalid",
                'password_hash' => 'test-hash', 'status' => 'active', 'account_type' => 'hunter',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        DB::connection('identity')->table('users')
            ->whereIn('id', [$this->pendingUserId, $this->activeUserId])->delete();

        parent::tearDown();
    }

    public function test_pending_user_can_reach_verify_email_notice(): void
    {
        $this->withSession(['auth.user_id' => $this->pendingUserId])
            ->get('/email/verify')
            ->assertOk();
    }

    public function test_active_user_can_also_reach_verify_email_notice(): void
    {
        $this->withSession(['auth.user_id' => $this->activeUserId])
            ->get('/email/verify')
            ->assertOk();
    }

    public function test_pending_user_is_still_blocked_from_member_area(): void
    {
        $this->withSession(['auth.user_id' => $this->pendingUserId])
            ->get('/member/profile')
            ->assertRedirect(route('auth.login'));
    }

    public function test_unauthenticated_visitor_is_redirected_from_notice(): void
    {
        $this->get('/email/verify')->assertRedirect(route('auth.login'));
    }
}
