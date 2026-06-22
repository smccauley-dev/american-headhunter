<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Jobs\Identity\SendEmailVerificationJob;
use App\Services\Auth\AuthService;
use App\Services\Auth\MfaService;
use App\Services\Auth\SessionService;
use App\Services\Billing\MembershipCheckoutService;
use App\Services\Billing\PromotionAutoApplyService;
use App\Services\Documents\DocumentService;
use App\Services\Identity\OfacService;
use App\Services\Identity\ServiceVerificationService;
use App\Services\Identity\UserService;
use App\Services\Platform\PlanService;
use App\Services\Mfa\MfaMethodRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService    $auth,
        private readonly MfaService     $mfa,
        private readonly SessionService $session,
        private readonly UserService    $users,
        private readonly OfacService    $ofac,
        private readonly MfaMethodRegistry $mfaRegistry,
    ) {}

    public function getStarted(Request $request): Response
    {
        $planKey = $request->query('plan');
        $plan    = $planKey ? app(PlanService::class)->findPublicPlan($planKey) : null;

        return Inertia::render('Auth/GetStarted', [
            // {plan_key, display_name, account_type, is_paid, …prices} | null —
            // pre-selects the matching role and follows the user to register.
            'plan'     => $plan,
            // Billing cycle carried from the pricing page's toggle.
            'interval' => $request->query('interval') === 'annual' ? 'annual' : 'monthly',
        ]);
    }

    public function showRegister(Request $request): Response
    {
        $accountType = $request->query('type', 'hunter');

        $planKey = $request->query('plan');
        $plan    = $planKey ? app(PlanService::class)->findPublicPlan($planKey) : null;

        // Only carry a plan that matches the chosen account type.
        if ($plan && $plan['account_type'] !== $accountType) {
            $plan = null;
        }

        $verifications = app(ServiceVerificationService::class);

        return Inertia::render('Auth/Register', [
            'accountType'    => $accountType,
            'legalUrls'      => config('platform.legal'),
            'signupPromo'    => app(PromotionAutoApplyService::class)->previewForSignup($accountType),
            'signupPlan'     => $plan,
            'signupInterval' => $request->query('interval') === 'annual' ? 'annual' : 'monthly',
            // Method switch per type ('manual' | 'id_me' | 'both') — drives whether
            // the optional step offers a document upload or defers to ID.me.
            'serviceMethods' => [
                'veteran'         => $verifications->methodFor(ServiceVerificationService::TYPE_VETERAN),
                'first_responder' => $verifications->methodFor(ServiceVerificationService::TYPE_FIRST_RESPONDER),
            ],
        ]);
    }

    public function register(RegisterRequest $request): HttpResponse|RedirectResponse
    {
        $validated = $request->validated();

        $user = $this->users->create(array_merge(
            $validated,
            [
                'ip_address'  => $request->ip(),
                'user_agent'  => $request->userAgent(),
                'tos_version' => '2026-01-01',
            ]
        ));

        // Screen against OFAC — suspends account internally on match
        $this->ofac->screen($user);

        // Optional service-status step: open a pending veteran / first-responder
        // verification if the applicant declared one and attached proof.
        $this->submitServiceVerification($request, $user);

        // Dispatch verification email
        SendEmailVerificationJob::dispatch($user->id);

        // Log the user in immediately (unverified session)
        $request->session()->regenerate();
        $request->session()->put('auth.user_id', $user->id);

        // A paid plan chosen at signup goes straight to Stripe Checkout — payment
        // happens now, while intent is high; email verification continues in
        // parallel via the notice screen. Free/unknown plans skip checkout.
        if ($redirect = $this->startSignupCheckout($user, $validated)) {
            return $redirect;
        }

        return redirect()->route('auth.verify-email.notice');
    }

    /**
     * Open a pending service-status verification for a just-registered user, when
     * they declared veteran / first responder and attached proof. Manual-upload
     * path only — when the type's method switch is 'id_me' the upload is ignored
     * (ID.me runs post-signup). Skipping the step, omitting the file, or any
     * failure here must never break registration, so it's all best-effort.
     */
    private function submitServiceVerification(RegisterRequest $request, \App\Models\Identity\User $user): void
    {
        $type = $request->input('service_status');
        $file = $request->file('service_proof');

        if (! $type || ! $file) {
            return;
        }

        try {
            $verifications = app(ServiceVerificationService::class);

            // The admin switch can disable the manual path in favour of ID.me;
            // in that case we don't accept an uploaded document at signup.
            if ($verifications->methodFor($type) === 'id_me') {
                return;
            }

            $document = app(DocumentService::class)->storeUploadedFile($file, $user->id, 'id_document');
            $verifications->createPending($user, $type, $verifications->uploadMethodFor($type), $document->id);
        } catch (\Throwable $e) {
            Log::warning('Service-status verification submission failed at signup', [
                'user_id' => $user->id,
                'type'    => $type,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * If the user picked a paid plan for their account type, start a hosted Stripe
     * Checkout and return the redirect to it. Returns null (so registration falls
     * through to the email-verification notice) for free/unknown plans, or if the
     * session can't be created — a Stripe hiccup must never block signup, and the
     * stored intended_plan_key still routes them to checkout at first login.
     */
    private function startSignupCheckout(\App\Models\Identity\User $user, array $validated): ?HttpResponse
    {
        $planKey = $validated['plan'] ?? null;
        $plan    = $planKey ? app(PlanService::class)->findPublicPlan($planKey) : null;

        if (! $plan || ! $plan['is_paid'] || $plan['account_type'] !== $user->account_type) {
            return null;
        }

        $interval = ($validated['interval'] ?? null) === 'annual' ? 'annual' : 'monthly';

        $result = app(MembershipCheckoutService::class)->start(
            user:       $user,
            planKey:    $plan['plan_key'],
            interval:   $interval,
            promoCode:  null,
            successUrl: route('auth.verify-email.notice') . '?checkout=success',
            cancelUrl:  route('auth.verify-email.notice') . '?checkout=cancel',
        );

        if (! isset($result['url'])) {
            Log::warning('Signup checkout could not start; falling back to email notice', [
                'user_id' => $user->id,
                'error'   => $result['error'] ?? 'unknown',
            ]);
            return null;
        }

        // Acted on the choice — clear the deferred fallback so it can't re-route
        // the user at first login on top of the checkout they just started.
        $this->users->clearIntendedPlan($user);

        return Inertia::location($result['url']);
    }

    public function showVerifyEmailNotice(): Response
    {
        return Inertia::render('Auth/VerifyEmail');
    }

    public function resendVerification(Request $request): RedirectResponse
    {
        $userId = $request->session()->get('auth.user_id');
        if ($userId) {
            $user = $this->users->findById($userId);
            if ($user && ! $user->email_verified_at) {
                SendEmailVerificationJob::dispatch($user->id);
            }
        }

        return back()->with('success', 'Verification email sent. Check your inbox.');
    }

    /**
     * Polled by the post-signup notice screen. Reads the waiting session's user
     * fresh (the verification link may have been clicked on another device, so a
     * cached copy could be stale) and, once verified, hands back where to send
     * this tab — the same paid→checkout / free→member destination login uses, so
     * no second sign-in is required.
     */
    public function verifyEmailStatus(Request $request): \Illuminate\Http\JsonResponse
    {
        $userId = $request->session()->get('auth.user_id');
        $user   = $userId ? $this->users->findFresh($userId) : null;

        if (! $user || ! $user->email_verified_at) {
            return response()->json(['verified' => false]);
        }

        $redirect = $this->users->takeIntendedPlanRedirect($user)
            ?? app(\App\Services\Platform\TenantService::class)->getSetting('nav.login_redirect', '/member/profile');

        return response()->json(['verified' => true, 'redirect' => $redirect]);
    }

    public function verifyEmail(Request $request, string $token): RedirectResponse
    {
        $userId = $request->query('id');

        if (! $userId) {
            return redirect()->route('auth.login')->with('error', 'Invalid verification link.');
        }

        $verificationService = app(\App\Services\Identity\VerificationService::class);
        $success = $verificationService->verifyEmail($userId, $token);

        if (! $success) {
            return redirect()->route('auth.verify-email.notice')
                ->with('error', 'Verification link is invalid or has expired.');
        }

        return redirect()->route('auth.login')
            ->with('success', 'Email verified. You can now log in.');
    }

    public function showLogin(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function login(LoginRequest $request): RedirectResponse
    {
        $user = $this->auth->attempt(
            $request->input('email'),
            $request->input('password'),
            $request
        );

        if (! $user) {
            return back()->withErrors(['email' => 'These credentials do not match our records.']);
        }

        if (! $user->isActive()) {
            return back()->withErrors(['email' => 'Your account is not active. Please verify your email.']);
        }

        // MFA required?
        if ($this->mfa->isEnabled($user)) {
            $this->session->markMfaPending($request->session()->getId(), $user->id);

            // Push-style factors (email/SMS) need a code dispatched now; TOTP
            // codes come from the authenticator app (triggerChallenge no-ops).
            foreach ($this->mfa->getEnabledMethods($user) as $method) {
                $this->mfaRegistry->get($method)->triggerChallenge($user, $request->ip());
            }

            return redirect()->route('auth.mfa.verify');
        }

        $request->session()->regenerate();
        $request->session()->put('auth.user_id', $user->id);

        // First login after signing up with a chosen plan: a paid plan routes to
        // checkout, a free plan needs no action. Consumed once, then cleared.
        if ($planRedirect = $this->users->takeIntendedPlanRedirect($user)) {
            return redirect()->to($planRedirect);
        }

        return redirect()->intended(
            app(\App\Services\Platform\TenantService::class)->getSetting('nav.login_redirect', '/member/profile')
        );
    }

    public function logout(Request $request): RedirectResponse
    {
        $userId = $request->session()->get('auth.user_id');
        if ($userId) {
            $user = $this->users->findById($userId);
            if ($user) {
                $this->auth->logout($user, $request);
            }
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->to(config('platform.logout_redirect_url', '/'));
    }
}
