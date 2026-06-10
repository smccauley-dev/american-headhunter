<?php

namespace App\Contracts\Mfa;

use App\Models\Identity\User;

interface MfaMethodContract
{
    public function method(): string;

    /** Deliver a challenge code (no-op for TOTP — authenticator app handles it). */
    public function triggerChallenge(User $user, string $ipAddress): void;

    /** Verify a submitted code against the user's enrolled factor. */
    public function verify(User $user, string $code): bool;
}
