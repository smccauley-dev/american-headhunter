<?php

namespace Tests\Feature\Member;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The reactivation waiting room for a paused account (a lapsed 'pause_account'
 * promo). A paused member authenticates normally and is routed to /reactivate,
 * which is guarded by `auth.session:allow-paused`. This pins the contract:
 *
 *   - login admits a paused user and redirects them to the waiting room (they are
 *     no longer rejected at AuthService::attempt),
 *   - a paused session CAN reach /reactivate,
 *   - an already-active session visiting /reactivate is sent on to /member,
 *   - a paused session still CANNOT reach active-only areas (/member), so the
 *     lighter allow-paused check is not a backdoor.
 *
 * Fixtures are committed (not wrapped in a transaction) because login and the
 * reactivate routes run under `db.system` (ah_system) and would not see rows
 * still pending in a test-held transaction. They are removed in tearDown.
 */
class ReactivationAccessTest extends TestCase
{
    private string $pausedUserId;
    private string $activeUserId;
    private string $password = 'reactivate-secret-123';

    protected function setUp(): void
    {
        parent::setUp();

        $this->pausedUserId = (string) Str::uuid();
        $this->activeUserId = (string) Str::uuid();

        DB::connection('identity')->table('users')->insert([
            [
                'id' => $this->pausedUserId, 'email' => "paused-{$this->pausedUserId}@test.invalid",
                'password_hash' => Hash::make($this->password), 'status' => 'paused', 'account_type' => 'hunter',
            ],
            [
                'id' => $this->activeUserId, 'email' => "active-{$this->activeUserId}@test.invalid",
                'password_hash' => Hash::make($this->password), 'status' => 'active', 'account_type' => 'hunter',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        DB::connection('identity')->table('login_history')
            ->whereIn('user_id', [$this->pausedUserId, $this->activeUserId])->delete();
        DB::connection('identity')->table('users')
            ->whereIn('id', [$this->pausedUserId, $this->activeUserId])->delete();

        parent::tearDown();
    }

    public function test_paused_user_login_redirects_to_reactivation_room(): void
    {
        $this->post(route('auth.login.submit'), [
            'email'    => "paused-{$this->pausedUserId}@test.invalid",
            'password' => $this->password,
        ])->assertRedirect(route('reactivate.show'));
    }

    public function test_paused_user_can_reach_reactivation_room(): void
    {
        $this->withSession(['auth.user_id' => $this->pausedUserId])
            ->get('/reactivate')
            ->assertOk();
    }

    public function test_active_user_visiting_reactivation_room_is_sent_to_member(): void
    {
        $this->withSession(['auth.user_id' => $this->activeUserId])
            ->get('/reactivate')
            ->assertRedirect('/member');
    }

    public function test_paused_user_is_still_blocked_from_member_area(): void
    {
        $this->withSession(['auth.user_id' => $this->pausedUserId])
            ->get('/member/profile')
            ->assertRedirect(route('auth.login'));
    }

    public function test_unauthenticated_visitor_is_redirected_from_reactivation_room(): void
    {
        $this->get('/reactivate')->assertRedirect(route('auth.login'));
    }
}
