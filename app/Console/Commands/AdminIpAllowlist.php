<?php

namespace App\Console\Commands;

use App\Services\Platform\TenantService;
use Illuminate\Console\Command;

class AdminIpAllowlist extends Command
{
    protected $signature = 'admin:ip-allowlist
                            {action : list | clear | add {ip}}
                            {ip? : IP address or CIDR to add}';

    protected $description = 'Manage the admin panel IP allowlist from the CLI (bypasses web middleware)';

    public function handle(TenantService $tenant): int
    {
        $action = $this->argument('action');

        match ($action) {
            'list'  => $this->listEntries($tenant),
            'clear' => $this->clearList($tenant),
            'add'   => $this->addEntry($tenant),
            default => $this->error("Unknown action '{$action}'. Use: list | clear | add {ip}"),
        };

        return self::SUCCESS;
    }

    private function listEntries(TenantService $tenant): void
    {
        $ips = (array) $tenant->getSetting('admin.ip_allowlist', []);

        if (empty($ips)) {
            $this->info('Allowlist is empty — all IPs are currently permitted.');
            return;
        }

        $this->info('Current admin IP allowlist:');
        foreach ($ips as $ip) {
            $this->line("  • {$ip}");
        }
    }

    private function clearList(TenantService $tenant): void
    {
        $tenant->setSetting('admin.ip_allowlist', []);
        $this->info('✓ Allowlist cleared — all IPs are now permitted.');
    }

    private function addEntry(TenantService $tenant): void
    {
        $ip = $this->argument('ip');

        if (! $ip) {
            $this->error('Provide an IP address: php artisan admin:ip-allowlist add 1.2.3.4');
            return;
        }

        $ips = (array) $tenant->getSetting('admin.ip_allowlist', []);

        if (in_array($ip, $ips)) {
            $this->warn("{$ip} is already in the allowlist.");
            return;
        }

        $ips[] = $ip;
        $tenant->setSetting('admin.ip_allowlist', array_values($ips));
        $this->info("✓ Added {$ip} to the allowlist.");
    }
}
