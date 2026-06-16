<?php

namespace App\Services\Auth;

use App\Models\Identity\MfaChallenge;
use App\Models\Identity\MfaConfiguration;
use App\Models\Identity\User;
use App\Services\Audit\AuditService;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class MfaService extends BaseService
{
    private const CHALLENGE_TTL_MINUTES = 10;
    private const BACKUP_CODE_COUNT     = 10;

    public function __construct(private readonly AuditService $audit) {}

    public function isEnabled(User $user): bool
    {
        return MfaConfiguration::where('user_id', $user->id)
            ->where('is_enabled', true)
            ->exists();
    }

    public function getConfigurations(User $user): \Illuminate\Database\Eloquent\Collection
    {
        return MfaConfiguration::where('user_id', $user->id)->get();
    }

    /**
     * Names of the MFA methods the user currently has enabled (e.g. ['totp','email']).
     */
    public function getEnabledMethods(User $user): array
    {
        return MfaConfiguration::where('user_id', $user->id)
            ->where('is_enabled', true)
            ->pluck('method')
            ->all();
    }

    public function hasRecoveryCodes(User $user): bool
    {
        return DB::connection('identity')
            ->table('user_recovery_codes')
            ->where('user_id', $user->id)
            ->exists();
    }

    public function createChallenge(User $user, string $method, string $ipAddress): array
    {
        $code = (string) random_int(100000, 999999);

        MfaChallenge::create([
            'user_id'    => $user->id,
            'method'     => $method,
            'code_hash'  => Hash::make($code),
            'expires_at' => now()->addMinutes(self::CHALLENGE_TTL_MINUTES),
            'ip_address' => $ipAddress,
        ]);

        return ['code' => $code, 'expires_minutes' => self::CHALLENGE_TTL_MINUTES];
    }

    public function verifyChallenge(User $user, string $method, string $code): bool
    {
        $challenge = MfaChallenge::where('user_id', $user->id)
            ->where('method', $method)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->latest('created_at')
            ->first();

        if (! $challenge || ! Hash::check($code, $challenge->code_hash)) {
            return false;
        }

        $challenge->update(['used_at' => now()]);
        return true;
    }

    /**
     * Generate 10 fresh recovery codes. Replaces any existing codes.
     * Returns plaintext shown once — only bcrypt hashes are stored.
     * Codes are 11 chars (XXXXX-XXXXX), well under bcrypt 72-byte limit.
     */
    public function generateBackupCodes(User $user): array
    {
        DB::connection('identity')
            ->table('user_recovery_codes')
            ->where('user_id', $user->id)
            ->delete();

        $plainCodes = [];
        $rows       = [];

        for ($i = 0; $i < self::BACKUP_CODE_COUNT; $i++) {
            $code         = strtoupper(Str::random(5)) . '-' . strtoupper(Str::random(5));
            $plainCodes[] = $code;
            $rows[]       = [
                'user_id'   => $user->id,
                'code_hash' => Hash::make($code),
            ];
        }

        DB::connection('identity')->table('user_recovery_codes')->insert($rows);

        return $plainCodes;
    }

    /**
     * Verify a recovery code and immediately consume it (single-use).
     */
    public function verifyAndConsumeRecoveryCode(User $user, string $code): bool
    {
        $rows = DB::connection('identity')
            ->table('user_recovery_codes')
            ->where('user_id', $user->id)
            ->whereNull('used_at')
            ->get(['id', 'code_hash']);

        foreach ($rows as $row) {
            if (Hash::check($code, $row->code_hash)) {
                DB::connection('identity')
                    ->table('user_recovery_codes')
                    ->where('id', $row->id)
                    ->update(['used_at' => now()]);
                return true;
            }
        }

        return false;
    }

    /**
     * Permanently destroy all recovery codes (admin-reset path).
     */
    public function invalidateAllRecoveryCodes(User $user): void
    {
        DB::connection('identity')
            ->table('user_recovery_codes')
            ->where('user_id', $user->id)
            ->delete();
    }
}
