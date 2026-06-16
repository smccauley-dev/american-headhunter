<?php

namespace App\Services\Identity;

use App\Mail\MfaFactorEnabledByAdminMail;
use App\Mail\RecoveryCodesEmail;
use App\Models\Identity\ConsentLog;
use App\Models\Identity\MfaConfiguration;
use App\Models\Identity\User;
use App\Models\Identity\UserProfile;
use App\Services\Audit\AuditService;
use App\Services\Auth\MfaService;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class UserService extends BaseService
{
    private const CACHE_TTL_MINUTES = 15;

    public function __construct(
        private readonly AuditService $audit,
        private readonly MfaService   $mfa,
    ) {}

    public function findById(string $id): ?User
    {
        $result = $this->cache("user:{$id}", function () use ($id) {
            return User::with('profile')->find($id);
        }, self::CACHE_TTL_MINUTES);

        if ($result !== null && ! ($result instanceof User)) {
            // Stale serialized object from a previous deploy — bust and re-fetch
            $this->invalidate("user:{$id}");
            return User::with('profile')->find($id);
        }

        return $result;
    }

    public function findByEmail(string $email): ?User
    {
        return User::where('email', strtolower($email))->first();
    }

    public function create(array $data): User
    {
        $user = User::create([
            'email'         => strtolower($data['email']),
            'password_hash' => Hash::make($data['password']),
            'account_type'  => $data['account_type'],
            'status'        => 'pending_verification',
        ]);

        UserProfile::create([
            'user_id'    => $user->id,
            'first_name' => $data['first_name'] ?? null,
            'last_name'  => $data['last_name']  ?? null,
            'state_code' => $data['state_code'] ?? null,
        ]);

        ConsentLog::create([
            'user_id'      => $user->id,
            'consent_type' => 'terms_of_service',
            'granted'      => true,
            'version'      => $data['tos_version'],
            'ip_address'   => $data['ip_address'] ?? null,
            'user_agent'   => $data['user_agent'] ?? null,
        ]);

        $this->audit->logAccountCreated($user->id, $user->account_type);

        return $user;
    }

    public function updateProfile(User $user, array $profileData): UserProfile
    {
        $profile = $user->profile ?? UserProfile::create(['user_id' => $user->id]);
        $profile->fill($profileData)->save();

        $this->invalidate("user:{$user->id}");

        return $profile;
    }

    public function deactivate(User $user, string $actingUserId): void
    {
        $user->delete();

        $this->invalidate("user:{$user->id}");

        $this->audit->log(
            eventType:      'account_deactivated',
            sourceDatabase: 'ah_identity',
            tableName:      'users',
            recordId:       $user->id,
            userId:         $actingUserId,
            actionSummary:  'Account deactivated',
        );
    }

    /** Revoke all API tokens (all-device logout / compromise response). */
    public function revokeAllTokens(User $user): void
    {
        $user->tokens()->delete();
        $this->invalidate("user:{$user->id}");
    }

    /**
     * Disable a single MFA factor without touching other factors,
     * recovery codes, or active tokens. Use when one specific method
     * is compromised (e.g. email account taken over — disable email MFA,
     * leave TOTP active).
     */
    public function disableMfaFactor(User $user, string $method, ?string $adminUserId = null): void
    {
        // SEC-042 follow-up: clear the TOTP secret on disable so a later
        // re-enable cannot reactivate a stale secret the user may have already
        // removed from their authenticator app (which would lock them out at
        // login). Re-enabling TOTP always requires a fresh enrollment.
        $attributes = ['is_enabled' => false, 'verified_at' => null];
        if ($method === 'totp') {
            $attributes['secret_encrypted'] = null;
        }

        DB::connection('identity')
            ->table('mfa_configurations')
            ->where('user_id', $user->id)
            ->where('method', $method)
            ->update($attributes);

        $this->invalidate("user:{$user->id}");

        $this->audit->log(
            eventType:      'mfa_factor_disabled',
            sourceDatabase: 'ah_identity',
            tableName:      'mfa_configurations',
            recordId:       $user->id,
            userId:         $adminUserId,
            actionSummary:  "MFA factor disabled: {$method}"
                . ($method === 'totp' ? ' (secret cleared)' : '') . ' (admin-initiated)',
        );
    }

    /**
     * Admin-enable a single MFA factor. The factor is marked verified so it
     * is immediately active. TOTP can only be enabled if the user has already
     * enrolled an authenticator secret (self-service) — admins cannot enroll a
     * secret on the user's behalf, and enabling TOTP without one would lock the
     * user out at login with a code that can never validate.
     */
    public function enableMfaFactor(User $user, string $method, ?string $adminUserId = null): void
    {
        if ($method === 'totp' && ! $this->hasTotpSecret($user)) {
            throw new \RuntimeException(
                'This user has not enrolled an authenticator app yet. Ask them to set it up '
                . 'from their Security settings; TOTP cannot be enabled without an enrolled secret.'
            );
        }

        $cfg = MfaConfiguration::firstOrNew([
            'user_id' => $user->id,
            'method'  => $method,
        ]);
        $cfg->is_enabled  = true;
        $cfg->verified_at = now();
        $cfg->save();

        $this->invalidate("user:{$user->id}");

        $this->audit->log(
            eventType:      'mfa_factor_enabled',
            sourceDatabase: 'ah_identity',
            tableName:      'mfa_configurations',
            recordId:       $user->id,
            userId:         $adminUserId,
            actionSummary:  "MFA factor enabled: {$method} (admin-initiated)",
        );

        $this->notifyUserFactorEnabled($user, $method);
    }

    /**
     * Out-of-band notice to the account holder that an admin enabled a 2FA
     * method, so they know their login now requires a second factor and how to
     * recover if they cannot complete it. Never throws — a mail failure must
     * not roll back the security change.
     */
    private function notifyUserFactorEnabled(User $user, string $method): void
    {
        try {
            Mail::to($user->email)->send(
                new MfaFactorEnabledByAdminMail($this->methodLabel($method)),
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Human-readable 2FA method name for user-facing notifications. */
    private function methodLabel(string $method): string
    {
        return match ($method) {
            'totp'  => 'Authenticator app (TOTP)',
            'email' => 'Email',
            'sms'   => 'SMS',
            default => ucfirst($method),
        };
    }

    /**
     * Whether the user has an enrolled TOTP secret on file.
     */
    private function hasTotpSecret(User $user): bool
    {
        return DB::connection('identity')
            ->table('mfa_configurations')
            ->where('user_id', $user->id)
            ->where('method', 'totp')
            ->whereNotNull('secret_encrypted')
            ->exists();
    }

    /**
     * Clear the stored TOTP secret and disable the factor. The user must
     * re-scan a fresh QR code to re-enroll.
     */
    public function clearTotpSecret(User $user, ?string $adminUserId = null): void
    {
        DB::connection('identity')
            ->table('mfa_configurations')
            ->where('user_id', $user->id)
            ->where('method', 'totp')
            ->update([
                'is_enabled'       => false,
                'secret_encrypted' => null,
                'verified_at'      => null,
            ]);

        $this->invalidate("user:{$user->id}");

        $this->audit->log(
            eventType:      'mfa_totp_secret_cleared',
            sourceDatabase: 'ah_identity',
            tableName:      'mfa_configurations',
            recordId:       $user->id,
            userId:         $adminUserId,
            actionSummary:  'TOTP secret cleared — user must re-enroll (admin-initiated)',
        );
    }

    /**
     * Generate a fresh set of recovery codes and email them directly to
     * the user. The admin never sees the raw codes — they go straight to
     * the user's inbox. Use when a user has exhausted or lost their codes
     * but still has an active MFA factor (so a full MFA reset is unnecessary).
     */
    public function adminRegenerateRecoveryCodes(User $user, ?string $adminUserId = null): void
    {
        $codes = $this->mfa->generateBackupCodes($user);

        Mail::to($user->email)->send(new RecoveryCodesEmail($codes));

        $this->audit->log(
            eventType:      'recovery_codes_generated',
            sourceDatabase: 'ah_identity',
            tableName:      'user_recovery_codes',
            recordId:       $user->id,
            userId:         $adminUserId,
            actionSummary:  'Recovery codes regenerated and emailed to user (admin-initiated)',
        );
    }

    /**
     * Atomic MFA reset — disables all factors, destroys all recovery codes,
     * and revokes all active PATs. Used by admin "Reset MFA" action.
     */
    public function resetMfa(User $user, ?string $initiatedByUserId = null): void
    {
        DB::connection('identity')->transaction(function () use ($user) {
            DB::connection('identity')
                ->table('mfa_configurations')
                ->where('user_id', $user->id)
                ->update(['is_enabled' => false, 'verified_at' => null]);

            DB::connection('identity')
                ->table('user_recovery_codes')
                ->where('user_id', $user->id)
                ->delete();

            $user->tokens()->delete();
        });

        $this->invalidate("user:{$user->id}");

        $this->audit->log(
            eventType:      'mfa_reset',
            sourceDatabase: 'ah_identity',
            tableName:      'mfa_configurations',
            recordId:       $user->id,
            userId:         $initiatedByUserId,
            actionSummary:  'All MFA factors disabled, recovery codes deleted, tokens revoked'
                . ($initiatedByUserId ? ' (admin-initiated)' : ''),
        );
    }
}
