<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\MfaService;
use App\Services\Auth\SessionService;
use App\Services\Audit\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MfaController extends Controller
{
    public function __construct(
        private readonly MfaService     $mfa,
        private readonly SessionService $session,
        private readonly AuditService   $audit,
    ) {}

    public function show(Request $request): Response|RedirectResponse
    {
        $userId = $this->session->getMfaPendingUserId($request->session()->getId());

        if (! $userId) {
            return redirect()->route('auth.login');
        }

        return Inertia::render('Auth/MfaVerify');
    }

    public function verify(Request $request): RedirectResponse
    {
        $request->validate(['code' => 'required|string']);

        $sessionId = $request->session()->getId();
        $userId    = $this->session->getMfaPendingUserId($sessionId);

        if (! $userId) {
            return redirect()->route('auth.login');
        }

        $user = app(\App\Services\Identity\UserService::class)->findById($userId);

        if (! $user) {
            return redirect()->route('auth.login');
        }

        $code = $request->input('code');

        // Try TOTP challenge first, then backup code
        $valid = $this->mfa->verifyChallenge($user, 'totp', $code)
            || $this->mfa->verifyChallenge($user, 'sms', $code)
            || $this->mfa->verifyChallenge($user, 'email', $code)
            || $this->mfa->verifyBackupCode($user, $code);

        if (! $valid) {
            $this->audit->log(
                eventType:     'mfa_failed',
                sourceDatabase: 'ah_identity',
                tableName:     'mfa_challenges',
                recordId:      $userId,
                userId:        $userId,
                ipAddress:     $request->ip(),
            );

            return back()->withErrors(['code' => 'Invalid verification code.']);
        }

        $this->session->clearMfaPending($sessionId);
        $this->session->markMfaVerified($sessionId);

        $request->session()->regenerate();
        $request->session()->put('auth.user_id', $userId);

        $this->audit->log(
            eventType:     'mfa_verified',
            sourceDatabase: 'ah_identity',
            tableName:     'mfa_configurations',
            recordId:      $userId,
            userId:        $userId,
            ipAddress:     $request->ip(),
        );

        return redirect()->intended(
            app(\App\Services\Platform\TenantService::class)->getSetting('nav.login_redirect', '/member/profile')
        );
    }
}
