<?php

namespace App\Services\Identity;

use App\Models\Identity\EmailVerificationToken;
use App\Models\Identity\User;
use App\Services\Audit\AuditService;
use App\Services\BaseService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class VerificationService extends BaseService
{
    private const EMAIL_TOKEN_TTL_HOURS = 24;

    public function __construct(private readonly AuditService $audit) {}

    /**
     * Generate an email verification token and store its hash.
     * Returns the plaintext token for inclusion in the verification email.
     */
    public function createEmailToken(User $user): string
    {
        // Expire any existing unused tokens before issuing a new one
        EmailVerificationToken::where('user_id', $user->id)
            ->whereNull('verified_at')
            ->update(['expires_at' => now()]);

        $token = Str::random(64);

        EmailVerificationToken::create([
            'user_id'    => $user->id,
            'email'      => $user->email,
            'token_hash' => Hash::make($token),
            'expires_at' => now()->addHours(self::EMAIL_TOKEN_TTL_HOURS),
        ]);

        return $token;
    }

    /**
     * Verify an email token. Marks the token as used and sets email_verified_at on the user.
     * Returns true on success, false if invalid/expired.
     */
    public function verifyEmail(string $userId, string $token): bool
    {
        $record = EmailVerificationToken::where('user_id', $userId)
            ->whereNull('verified_at')
            ->where('expires_at', '>', now())
            ->latest('created_at')
            ->first();

        if (! $record || ! Hash::check($token, $record->token_hash)) {
            return false;
        }

        $record->update(['verified_at' => now()]);

        $user = User::find($userId);
        if ($user) {
            $user->update([
                'email_verified_at' => now(),
                'status'            => 'active',
            ]);

            $this->audit->log(
                eventType:     'email_verified',
                sourceDatabase: 'ah_identity',
                tableName:     'users',
                recordId:      $user->id,
                userId:        $user->id,
                actionSummary: 'Email address verified',
            );
        }

        return true;
    }

    /**
     * Handle a Checkr webhook updating a background check status.
     */
    public function handleBackgroundCheckWebhook(array $payload): void
    {
        $reportId = $payload['data']['object']['id'] ?? null;
        if (! $reportId) {
            return;
        }

        \App\Models\Identity\BackgroundCheckResult::where('provider_report_id', $reportId)
            ->update([
                'status'       => $payload['data']['object']['status'] ?? 'pending',
                'completed_at' => now(),
                'expires_at'   => now()->addYears(2),
            ]);
    }
}
