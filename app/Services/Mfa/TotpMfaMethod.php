<?php

namespace App\Services\Mfa;

use App\Contracts\Mfa\MfaMethodContract;
use App\Models\Identity\User;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;

class TotpMfaMethod implements MfaMethodContract
{
    public function __construct(private readonly Google2FA $google2fa) {}

    public function method(): string
    {
        return 'totp';
    }

    /** TOTP codes come from the authenticator app — nothing to send. */
    public function triggerChallenge(User $user, string $ipAddress): void {}

    public function verify(User $user, string $code): bool
    {
        $secret = $this->decryptSecret($user->id);
        if (! $secret) {
            return false;
        }
        return (bool) $this->google2fa->verifyKey($secret, $code);
    }

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey();
    }

    public function qrCodeUrl(string $email, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl(
            company: config('app.name'),
            holder:  $email,
            secret:  $secret,
        );
    }

    public function storeSecret(User $user, string $secret): void
    {
        $key = config('database.connections.identity.options.encryption_key');

        DB::connection('identity')->statement(
            "INSERT INTO mfa_configurations (id, user_id, method, is_enabled, secret_encrypted)
             VALUES (gen_random_uuid(), ?, 'totp', false, pgp_sym_encrypt(?, ?))
             ON CONFLICT (user_id, method) DO UPDATE SET
                 secret_encrypted = EXCLUDED.secret_encrypted,
                 is_enabled = false,
                 verified_at = NULL",
            [$user->id, $secret, $key]
        );
    }

    private function decryptSecret(string $userId): ?string
    {
        $key = config('database.connections.identity.options.encryption_key');

        $row = DB::connection('identity')->selectOne(
            "SELECT pgp_sym_decrypt(secret_encrypted::bytea, ?) AS secret
             FROM mfa_configurations
             WHERE user_id = ? AND method = 'totp'",
            [$key, $userId]
        );

        return $row?->secret;
    }
}
