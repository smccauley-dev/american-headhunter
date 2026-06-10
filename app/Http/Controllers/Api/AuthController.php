<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Identity\UserResource;
use App\Models\Identity\MfaConfiguration;
use App\Models\Identity\User;
use App\Services\Auth\AuthService;
use App\Services\Auth\MfaService;
use App\Services\Auth\SessionService;
use App\Services\Identity\UserService;
use App\Services\Mfa\MfaMethodRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService       $authService,
        private readonly MfaService        $mfaService,
        private readonly SessionService    $sessionService,
        private readonly UserService       $userService,
        private readonly MfaMethodRegistry $mfaRegistry,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'    => ['required', 'email'],
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
                'mfa_required'    => true,
                'challenge_token' => $challengeToken,
                'mfa_methods'     => $methods,
            ]);
        }

        $token = $this->sessionService->issueToken($user, [
            'hunter:read', 'hunter:apply', 'hunter:checkin',
        ]);

        return response()->json([
            'token' => $token,
            'user'  => new UserResource($user->load('profile')),
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
            'method'          => ['required', 'string', 'in:sms,email'],
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
            'method'          => ['required', 'string', 'in:totp,sms,email'],
            'code'            => ['required', 'string'],
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

        $token = $this->sessionService->issueToken($user, [
            'hunter:read', 'hunter:apply', 'hunter:checkin',
        ]);

        return response()->json([
            'token' => $token,
            'user'  => new UserResource($user->load('profile')),
        ]);
    }
}
