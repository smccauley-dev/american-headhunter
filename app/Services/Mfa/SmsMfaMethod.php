<?php

namespace App\Services\Mfa;

use App\Contracts\Mfa\MfaMethodContract;
use App\Contracts\Sms\SmsDriver;
use App\Models\Identity\User;
use App\Services\Auth\MfaService;
use RuntimeException;

class SmsMfaMethod implements MfaMethodContract
{
    public function __construct(
        private readonly MfaService $mfaService,
        private readonly SmsDriver  $smsDriver,
    ) {}

    public function method(): string
    {
        return 'sms';
    }

    public function triggerChallenge(User $user, string $ipAddress): void
    {
        if (! $user->phone) {
            throw new RuntimeException('No phone number on account.');
        }

        $challenge = $this->mfaService->createChallenge($user, 'sms', $ipAddress);

        $this->smsDriver->send(
            $user->phone,
            "Your American Headhunter code: {$challenge['code']}. Valid {$challenge['expires_minutes']} min."
        );
    }

    public function verify(User $user, string $code): bool
    {
        return $this->mfaService->verifyChallenge($user, 'sms', $code);
    }
}
