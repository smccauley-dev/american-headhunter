<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\Identity\UserResource;
use App\Jobs\Identity\SendEmailVerificationJob;
use App\Models\Identity\MfaConfiguration;
use App\Models\Identity\User;
use App\Services\Auth\AuthService;
use App\Services\Auth\MfaService;
use App\Services\Auth\SessionService;
use App\Services\Identity\OfacService;
use App\Services\Identity\UserService;
use App\Services\Mfa\MfaMethodRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    /** Abilities granted to every member mobile token. */
    private const MEMBER_ABILITIES = ['hunter:read', 'hunter:apply', 'hunter:checkin', 'hunter:harvest'];

    public function __construct(
        private readonly AuthService $authService,
        private readonly MfaService $mfaService,
        private readonly SessionService $sessionService,
        private readonly UserService $userService,
        private readonly MfaMethodRegistry $mfaRegistry,
        private readonly OfacService $ofacService,
    ) {}

    /**
     * Mobile registration. Mirrors the web Auth\AuthController::register account
     * creation (UserService::create → OFAC screen → verification email) but,
     * instead of opening a web session and routing to Stripe Checkout, issues a
     * Sanctum token immediately so the app is signed in. A paid plan chosen at
     * signup is persisted as the user's intended_plan_key and surfaced to the
     * client to drive checkout; this endpoint never starts a charge. Runs on the
     * db.system path so the pre-context identity writes are not RLS-denied.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = $this->userService->create(array_merge($validated, [
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'tos_version' => '2026-01-01',
        ]));

        // Screen against OFAC — suspends the account internally on a match.
        $this->ofacService->screen($user);

        SendEmailVerificationJob::dispatch($user->id);

        $token = $this->sessionService->issueToken($user, self::MEMBER_ABILITIES);

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user->load('profile')),
            // Non-null when a paid plan was chosen at signup — the app routes the
            // user to checkout. Free/unknown plans resolve to null.
            'intended_plan_key' => $user->intended_plan_key,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = $this->authService->attempt($data['email'], $data['password'], $request);

        if (! $user) {
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        if ($this->mfaService->isEnabled($user)) {
            $methods = MfaConfiguration::where('user_id', $user->id)
                ->where('is_enabled', true)
                ->pluck('method')
                ->all();

            $challengeToken = $this->sessionService->issueMfaChallengeToken($user, $methods);

            return response()->json([
                'mfa_required' => true,
                'challenge_token' => $challengeToken,
                'mfa_methods' => $methods,
            ]);
        }

        $token = $this->sessionService->issueToken($user, self::MEMBER_ABILITIES);

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user->load('profile')),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(null, 204);
    }

    public function revokeAll(Request $request): JsonResponse
    {
        $this->userService->revokeAllTokens($request->user());

        return response()->json(null, 204);
    }

    public function mfaSend(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge_token' => ['required', 'string'],
            'method' => ['required', 'string', 'in:sms,email'],
        ]);

        $payload = $this->sessionService->getMfaChallengePayload($data['challenge_token']);

        if (! $payload || ! in_array($data['method'], $payload['methods'])) {
            return response()->json(['message' => 'Invalid or expired challenge.'], 422);
        }

        $user = User::find($payload['user_id']);

        if (! $user) {
            return response()->json(['message' => 'Invalid challenge.'], 422);
        }

        $this->mfaRegistry->get($data['method'])->triggerChallenge($user, $request->ip());

        return response()->json(['sent' => true]);
    }

    public function mfaVerify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge_token' => ['required', 'string'],
            'method' => ['required', 'string', 'in:totp,sms,email'],
            'code' => ['required', 'string'],
        ]);

        // Peek without consuming — challenge stays valid for retries (rate-limited)
        $payload = $this->sessionService->getMfaChallengePayload($data['challenge_token']);

        if (! $payload || ! in_array($data['method'], $payload['methods'])) {
            return response()->json(['message' => 'Invalid or expired challenge.'], 422);
        }

        $user = User::find($payload['user_id']);

        if (! $user) {
            return response()->json(['message' => 'Invalid challenge.'], 422);
        }

        if (! $this->mfaRegistry->get($data['method'])->verify($user, $data['code'])) {
            return response()->json(['message' => 'Invalid code.'], 422);
        }

        // Consume the challenge token only on success
        $this->sessionService->consumeMfaChallengeToken($data['challenge_token']);

        $token = $this->sessionService->issueToken($user, self::MEMBER_ABILITIES);

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user->load('profile')),
        ]);
    }
}
