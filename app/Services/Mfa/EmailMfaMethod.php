<?php

namespace App\Services\Mfa;

use App\Contracts\Mfa\MfaMethodContract;
use App\Mail\MfaChallengeCode;
use App\Models\Identity\User;
use App\Services\Auth\MfaService;
use Illuminate\Support\Facades\Mail;

class EmailMfaMethod implements MfaMethodContract
{
    public function __construct(private readonly MfaService $mfaService) {}

    public function method(): string
    {
        return 'email';
    }

    public function triggerChallenge(User $user, string $ipAddress): void
    {
        $challenge = $this->mfaService->createChallenge($user, 'email', $ipAddress);
        Mail::to($user->email)->send(new MfaChallengeCode($challenge['code'], $challenge['expires_minutes']));
    }

    public function verify(User $user, string $code): bool
    {
        return $this->mfaService->verifyChallenge($user, 'email', $code);
    }
}
