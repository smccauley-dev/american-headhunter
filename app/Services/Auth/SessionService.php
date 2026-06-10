<?php

namespace App\Services\Auth;

use App\Models\Identity\User;
use App\Services\BaseService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class SessionService extends BaseService
{
    private const MFA_PENDING_TTL_MINUTES   = 10;
    private const MFA_CHALLENGE_TTL_MINUTES = 5;

    // ── Web session MFA helpers ──────────────────────────────────────────────

    public function markMfaPending(string $sessionId, string $userId): void
    {
        Cache::store('sessions')->put(
            "mfa_pending:{$sessionId}",
            $userId,
            now()->addMinutes(self::MFA_PENDING_TTL_MINUTES)
        );
    }

    public function getMfaPendingUserId(string $sessionId): ?string
    {
        return Cache::store('sessions')->get("mfa_pending:{$sessionId}");
    }

    public function clearMfaPending(string $sessionId): void
    {
        Cache::store('sessions')->forget("mfa_pending:{$sessionId}");
    }

    public function hasMfaVerified(string $sessionId): bool
    {
        return Cache::store('sessions')->has("mfa_verified:{$sessionId}");
    }

    public function markMfaVerified(string $sessionId): void
    {
        Cache::store('sessions')->put(
            "mfa_verified:{$sessionId}",
            true,
            now()->addHours(8)
        );
    }

    // ── API token issuance ───────────────────────────────────────────────────

    /** Issue a 365-day Sanctum personal access token and return the plaintext token. */
    public function issueToken(User $user, array $abilities): string
    {
        return $user->createToken(
            name:      'mobile',
            abilities: $abilities,
            expiresAt: now()->addDays(365),
        )->plainTextToken;
    }

    // ── API MFA challenge token (Valkey-backed, not a Sanctum PAT) ───────────

    /**
     * Issue a short-lived MFA challenge token stored in Valkey (sessions cluster).
     * Returns the UUID used as the challenge_token in subsequent API calls.
     */
    public function issueMfaChallengeToken(User $user, array $methods): string
    {
        $uuid = (string) Str::uuid();

        Cache::store('sessions')->put(
            "mfa_challenge:{$uuid}",
            ['user_id' => $user->id, 'methods' => $methods],
            now()->addMinutes(self::MFA_CHALLENGE_TTL_MINUTES)
        );

        return $uuid;
    }

    /** Peek at the challenge payload without consuming it. */
    public function getMfaChallengePayload(string $token): ?array
    {
        return Cache::store('sessions')->get("mfa_challenge:{$token}");
    }

    /** Retrieve and atomically delete the challenge payload. */
    public function consumeMfaChallengeToken(string $token): ?array
    {
        return Cache::store('sessions')->pull("mfa_challenge:{$token}") ?: null;
    }
}
