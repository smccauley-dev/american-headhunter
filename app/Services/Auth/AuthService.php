<?php

namespace App\Services\Auth;

use App\Models\Identity\LoginHistory;
use App\Models\Identity\User;
use App\Services\Audit\AuditService;
use App\Services\BaseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthService extends BaseService
{
    private const MAX_FAILED_ATTEMPTS = 5;
    private const LOCKOUT_MINUTES     = 30;

    public function __construct(
        private readonly AuditService $audit,
        private readonly MfaService   $mfa,
    ) {}

    /**
     * Attempt login. Returns the user on success, null on failure.
     * Records login history and audit log regardless of outcome.
     */
    public function attempt(string $email, string $password, Request $request): ?User
    {
        $user = User::where('email', strtolower($email))->first();

        if (! $user) {
            $this->recordFailedAttempt(null, 'not_found', $request);
            return null;
        }

        if ($user->status === 'suspended') {
            $this->recordFailedAttempt($user, 'account_suspended', $request);
            return null;
        }

        if ($user->status === 'banned') {
            $this->recordFailedAttempt($user, 'account_banned', $request);
            return null;
        }

        // A billing pause (a 'pause_account' promo lapsed) blocks portal access
        // until the user starts a paid subscription, which reactivates them. The
        // reactivation entry point is the link in the expiry email, not the login
        // form, so login is refused here like the moderation states above.
        if ($user->status === 'paused') {
            $this->recordFailedAttempt($user, 'account_paused', $request);
            return null;
        }

        if ($user->isLocked()) {
            $this->recordFailedAttempt($user, 'account_locked', $request);
            return null;
        }

        if (! Hash::check($password, $user->password_hash)) {
            $this->handleFailedPassword($user, $request);
            return null;
        }

        $this->recordSuccessfulLogin($user, $request);
        return $user;
    }

    public function logout(User $user, Request $request): void
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $this->audit->log(
            eventType:     'logout',
            sourceDatabase: 'ah_identity',
            tableName:     'users',
            recordId:      $user->id,
            userId:        $user->id,
            actionSummary: 'User logged out',
            ipAddress:     $request->ip(),
            userAgent:     $request->userAgent(),
        );
    }

    private function handleFailedPassword(User $user, Request $request): void
    {
        $attempts = $user->failed_login_attempts + 1;

        $update = ['failed_login_attempts' => $attempts];
        if ($attempts >= self::MAX_FAILED_ATTEMPTS) {
            $update['locked_until'] = now()->addMinutes(self::LOCKOUT_MINUTES);
        }

        $user->update($update);

        $this->recordFailedAttempt($user, 'wrong_password', $request);
    }

    private function recordSuccessfulLogin(User $user, Request $request): void
    {
        $user->update([
            'failed_login_attempts' => 0,
            'locked_until'          => null,
            'last_login_at'         => now(),
            'last_login_ip'         => $request->ip(),
        ]);

        LoginHistory::create([
            'user_id'    => $user->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'success'    => true,
        ]);

        $this->audit->logLogin($user->id, $request->ip(), $request->userAgent(), true);
    }

    private function recordFailedAttempt(?User $user, string $reason, Request $request): void
    {
        if ($user) {
            LoginHistory::create([
                'user_id'        => $user->id,
                'ip_address'     => $request->ip(),
                'user_agent'     => $request->userAgent(),
                'success'        => false,
                'failure_reason' => $reason,
            ]);

            $this->audit->logLogin($user->id, $request->ip(), $request->userAgent(), false);
        }
    }
}
