<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\DB;

class DatabaseServiceProvider extends ServiceProvider
{
    /**
     * Inject per-connection encryption keys from environment into database config.
     * Keys come from Azure Key Vault in production, environment variables in dev.
     * Never hardcode keys — this is the only place they are loaded into config.
     */
    public function boot(): void
    {
        $this->injectEncryptionKeys();
    }

    private function injectEncryptionKeys(): void
    {
        $keyMap = [
            'identity'  => env('ENCRYPTION_KEY_IDENTITY'),
            'property'  => env('ENCRYPTION_KEY_PROPERTY'),
            'billing'   => env('ENCRYPTION_KEY_BILLING'),
            'lease'     => env('ENCRYPTION_KEY_LEASE'),
            'documents' => env('ENCRYPTION_KEY_DOCUMENTS'),
        ];

        // Set config('encryption_keys.<connection>') — used by HasEncryptedFields trait
        config(['encryption_keys' => $keyMap]);

        // Also index under the connection options for any raw-SQL callers
        foreach ($keyMap as $connection => $key) {
            if ($key) {
                config(["database.connections.{$connection}.options.encryption_key" => $key]);
            }
        }
    }
}
