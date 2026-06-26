<?php

namespace App\Providers;

use App\Database\RlsContext;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\DB;

class DatabaseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Request-scoped RLS context. Singleton so the middleware that arms it and
        // the ConnectionEstablished listener that consumes it share one instance.
        $this->app->singleton(RlsContext::class);
    }

    /**
     * Inject per-connection encryption keys from environment into database config.
     * Keys come from Azure Key Vault in production, environment variables in dev.
     * Never hardcode keys — this is the only place they are loaded into config.
     */
    public function boot(): void
    {
        $this->injectEncryptionKeys();
        $this->injectRlsContextOnConnect();
    }

    /**
     * SEC-055: apply the request's RLS context to each connection the moment it is
     * established (i.e. only the databases a request actually uses), and re-apply
     * after any reconnect/purge. Until InjectDatabaseContext arms the context this
     * is a no-op, so console, queue and test connections are unaffected.
     */
    private function injectRlsContextOnConnect(): void
    {
        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event): void {
            $context = $this->app->make(RlsContext::class);

            if ($context->isReady() && $context->appliesTo($event->connectionName)) {
                $context->applyTo($event->connection);
            }
        });
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
