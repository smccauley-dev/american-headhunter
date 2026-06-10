<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;



class EnsureAdminIpAllowed
{
    public function handle(Request $request, Closure $next): Response
    {
        $clientIp = $request->ip();

        // Server-level env bypass — set in .env, cannot be changed from the UI.
        // Must use config() not env() so it works when config cache is active.
        $envBypass = config('platform.admin_ip_bypass_ip');
        if ($envBypass && $clientIp === trim($envBypass)) {
            return $next($request);
        }

        // Local dev always bypasses
        if (app()->environment('local')) {
            return $next($request);
        }

        // Read directly from DB — skip cache so changes take effect immediately
        try {
            $rows = DB::connection('platform')
                ->table('tenant_settings')
                ->whereIn('key', ['admin.ip_allowlist', 'admin.bypass_ips'])
                ->get()
                ->keyBy('key');

            $allowlist = $rows->has('admin.ip_allowlist')
                ? json_decode($rows['admin.ip_allowlist']->value, true)
                : [];

            $dbBypassIps = $rows->has('admin.bypass_ips')
                ? (array) json_decode($rows['admin.bypass_ips']->value, true)
                : [];
        } catch (\Throwable) {
            return $next($request);
        }

        // DB-stored bypass IPs — editable from the admin UI
        foreach ($dbBypassIps as $bypassIp) {
            if ($clientIp === trim($bypassIp)) {
                return $next($request);
            }
        }

        // Empty list = allow all IPs
        if (empty($allowlist)) {
            return $next($request);
        }

        foreach ((array) $allowlist as $entry) {
            if ($this->ipMatches($clientIp, trim($entry))) {
                return $next($request);
            }
        }

        abort(403, 'Access denied: your IP address (' . $clientIp . ') is not on the admin allowlist.');
    }

    private function ipMatches(string $ip, string $cidr): bool
    {
        if ($ip === $cidr) {
            return true;
        }

        if (! str_contains($cidr, '/')) {
            return false;
        }

        [$subnet, $bits] = explode('/', $cidr, 2);

        if (! filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        $ipLong     = ip2long($ip);
        $subnetLong = ip2long($subnet);
        $mask       = $bits >= 32 ? -1 : ~((1 << (32 - (int) $bits)) - 1);

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
