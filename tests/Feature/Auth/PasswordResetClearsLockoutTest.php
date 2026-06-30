<?php

namespace Tests\Feature\Auth;

use App\Models\Identity\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression: a member who locked their account with 5 failed logins, then used
 * "Forgot password", stayed locked out. PasswordController::reset() only rewrote
 * password_hash and left failed_login_attempts / locked_until untouched — and
 * AuthService::attempt() checks isLocked() BEFORE the password, so even the new
 * password could not get in until the 30-minute lockout expired.
 *
 * A successful reset proves email ownership, so it must clear the lockout.
 *
 * The reset route runs under db.system, which reconnects as a different role, so
 * fixtures must be committed (not a rolled-back transaction) and cleaned up in
 * tearDown — the same pattern the other HTTP-driven feature tests use.
 */
class PasswordResetClearsLockoutTest extends TestCase
{
    private string $userId;
    private string $email;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = (string) Str::uuid();
        $this->email  = 'locked-' . Str::lower(Str::random(10)) . '@test.invalid';

        DB::connection('identity')->table('users')->insert([
            'id'                    => $this->userId,
            'email'                 => $this->email,
            'password_hash'         => Hash::make('old-password-123'),
            'status'                => 'active',
            'account_type'          => 'landowner',
            'failed_login_attempts' => 5,
            'locked_until'          => now()->addMinutes(30),
        ]);
    }

    protected function tearDown(): void
    {
        DB::connection('identity')->table('password_reset_tokens')->where('user_id', $this->userId)->delete();
        DB::connection('identity')->table('users')->where('id', $this->userId)->delete();

        parent::tearDown();
    }

    public function test_resetting_the_password_clears_a_failed_login_lockout(): void
    {
        // Precondition: the account is genuinely locked.
        $this->assertTrue(User::findOrFail($this->userId)->isLocked());

        $token = Str::random(64);
        DB::connection('identity')->table('password_reset_tokens')->insert([
            'id'         => (string) Str::uuid(),
            'user_id'    => $this->userId,
            'token_hash' => Hash::make($token),
            'expires_at' => now()->addHour(),
            'ip_address' => '127.0.0.1',
        ]);

        $newPassword = 'brand-new-password-456';

        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $this->post(route('auth.password.update'), [
            'email'                 => $this->email,
            'token'                 => $token,
            'password'              => $newPassword,
            'password_confirmation' => $newPassword,
        ])->assertRedirect(route('auth.login'));

        $user = User::findOrFail($this->userId);

        // The lockout is gone and the new password is in place, so the member can
        // actually log in now.
        $this->assertSame(0, (int) $user->failed_login_attempts);
        $this->assertNull($user->locked_until);
        $this->assertFalse($user->isLocked());
        $this->assertTrue(Hash::check($newPassword, $user->password_hash));
    }
}
