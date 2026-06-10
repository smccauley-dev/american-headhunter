<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class InjectDatabaseContext
{
    /**
     * Set PostgreSQL session-level variables for RLS policies.
     * These variables are read by policies like:
     *   USING (user_id = current_setting('app.current_user_id', true)::UUID)
     *
     * Runs on every request. Uses `true` as the second arg to current_setting()
     * so unset variables return NULL rather than throwing an error.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user     = $request->user();
        $userId   = $user?->id ?? '';
        $userRole = $user?->roles->first()?->name ?? '';

        $connections = [
            'identity', 'property', 'property_read',
            'lease', 'billing', 'wildlife', 'wildlife_read',
            'commerce', 'communications',
            'incidents', 'documents', 'platform',
            'geospatial', 'geospatial_read',
        ];

        foreach ($connections as $connection) {
            try {
                $pdo        = DB::connection($connection)->getPdo();
                $quotedId   = $pdo->quote($userId);
                $quotedRole = $pdo->quote($userRole);
                $conn = DB::connection($connection);
                $conn->unprepared("SET SESSION app.current_user_id = {$quotedId}");
                $conn->unprepared("SET SESSION app.user_role = {$quotedRole}");
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('RLS context injection failed', [
                    'connection' => $connection,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        return $next($request);
    }
}
