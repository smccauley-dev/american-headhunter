<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Identity\User;
use App\Services\Auth\MfaService;
use App\Services\Audit\AuditService;
use App\Services\Auth\SessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecoveryController extends Controller
{
    public function __construct(
        private readonly MfaService     $mfaService,
        private readonly SessionService $sessionService,
        private readonly AuditService   $audit,
    ) {}

    public function recover(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge_token' => ['required', 'string'],
            'recovery_code'   => ['required', 'string'],
        ]);

        $payload = $this->sessionService->getMfaChallengePayload($data['challenge_token']);

        if (! $payload) {
            return response()->json(['message' => 'Invalid or expired challenge token.'], 422);
        }

        $user = User::find($payload['user_id']);

        if (! $user) {
            return response()->json(['message' => 'Invalid or expired challenge token.'], 422);
        }

        if (! $this->mfaService->verifyAndConsumeRecoveryCode($user, $data['recovery_code'])) {
            return response()->json(['message' => 'Invalid recovery code.'], 422);
        }

        $this->sessionService->consumeMfaChallengeToken($data['challenge_token']);
        $this->audit->logRecoveryCodeUsed($user->id, $request->ip());

        $token = $this->sessionService->issueToken($user, ['hunter:read', 'hunter:apply', 'hunter:checkin']);

        return response()->json([
            'token'              => $token,
            'used_recovery_code' => true,
            'user'               => ['id' => $user->id, 'email' => $user->email],
        ]);
    }
}
