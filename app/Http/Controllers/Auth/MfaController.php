<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\MfaService;
use App\Services\Auth\SessionService;
use App\Services\Audit\AuditService;
use App\Services\Identity\UserService;
use App\Services\Mfa\MfaMethodRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MfaController extends Controller
{
    public function __construct(
        private readonly MfaService        $mfa,
        private readonly SessionService    $session,
        private readonly AuditService      $audit,
        private readonly UserService       $users,
        private readonly MfaMethodRegistry $registry,
    ) {}

    public function show(Request $request): Response|RedirectResponse
    {
        $userId = $this->session->getMfaPendingUserId($request->session()->getId());

        if (! $userId) {
            return redirect()->route('auth.login');
        }

        $user = $this->users->findById($userId);

        if (! $user) {
            return redirect()->route('auth.login');
        }

        return Inertia::render('Auth/MfaVerify', [
            // Which factors the user has enabled — lets the page label the input
            // and offer a "resend code" action for the push-style factors.
            'methods'        => $this->mfa->getEnabledMethods($user),
            'canResendCode'  => count(array_intersect($this->mfa->getEnabledMethods($user), ['email', 'sms'])) > 0,
        ]);
    }

    public function verify(Request $request): RedirectResponse
    {
        $request->validate(['code' => 'required|string']);

        $sessionId = $request->session()->getId();
        $userId    = $this->session->getMfaPendingUserId($sessionId);

        if (! $userId) {
            return redirect()->route('auth.login');
        }

        $user = $this->users->findById($userId);

        if (! $user) {
            return redirect()->route('auth.login');
        }

        $code = $request->input('code');

        // Try each enabled factor through its own verifier (TOTP checks the
        // google2fa secret; email/SMS check the stored challenge), then fall
        // back to a single-use recovery code.
        $valid = false;
        foreach ($this->mfa->getEnabledMethods($user) as $method) {
            if ($this->registry->get($method)->verify($user, $code)) {
                $valid = true;
                break;
            }
        }
        $valid = $valid || $this->mfa->verifyAndConsumeRecoveryCode($user, $code);

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

    public function resend(Request $request): RedirectResponse
    {
        $userId = $this->session->getMfaPendingUserId($request->session()->getId());

        if (! $userId) {
            return redirect()->route('auth.login');
        }

        $user = $this->users->findById($userId);

        if (! $user) {
            return redirect()->route('auth.login');
        }

        // Only the push-style factors have a code to (re)send.
        foreach (array_intersect($this->mfa->getEnabledMethods($user), ['email', 'sms']) as $method) {
            $this->registry->get($method)->triggerChallenge($user, $request->ip());
        }

        return back()->with('success', 'A new verification code has been sent.');
    }
}
