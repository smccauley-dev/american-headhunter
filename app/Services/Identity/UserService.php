<?php

namespace App\Services\Identity;

use App\Mail\RecoveryCodesEmail;
use App\Models\Identity\ConsentLog;
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
        return $this->cache("user:{$id}", function () use ($id) {
            return User::with('profile')->find($id);
        }, self::CACHE_TTL_MINUTES);
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
        DB::connection('identity')
            ->table('mfa_configurations')
            ->where('user_id', $user->id)
            ->where('method', $method)
            ->update(['is_enabled' => false, 'verified_at' => null]);

        $this->invalidate("user:{$user->id}");

        $this->audit->log(
            eventType:      'mfa_factor_disabled',
            sourceDatabase: 'ah_identity',
            tableName:      'mfa_configurations',
            recordId:       $user->id,
            userId:         $adminUserId,
            actionSummary:  "MFA factor disabled: {$method} (admin-initiated)",
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
