<x-filament-panels::page>
    @php
        $fmt   = fn ($n) => number_format((int) $n);
        $acres = fn ($n) => number_format((float) $n, 0);
        $money = fn ($cents) => '$' . number_format(((int) $cents) / 100, 2);
        $usersByType = $counts['users_by_type'] ?? [];
        $leasesByStatus = $counts['leases_by_status'] ?? [];
    @endphp

    <div
        x-data="{ tab: 'overview' }"
        class="fi-ta-ctn"
    >
        @if ($capturedAtHuman)
            <p class="fi-ta-updated" style="font-size: .8rem; opacity: .65; margin-bottom: 1rem;">
                Updated {{ $capturedAtHuman }}
            </p>
        @else
            <p class="fi-ta-updated" style="font-size: .8rem; opacity: .65; margin-bottom: 1rem;">
                No analytics yet — use <strong>Refresh now</strong> to compute the first snapshot.
            </p>
        @endif

        <x-filament::tabs>
            <x-filament::tabs.item alpine-active="tab === 'overview'" x-on:click="tab = 'overview'">
                Overview
            </x-filament::tabs.item>
            <x-filament::tabs.item alpine-active="tab === 'users'" x-on:click="tab = 'users'">
                Users
            </x-filament::tabs.item>
            <x-filament::tabs.item alpine-active="tab === 'props'" x-on:click="tab = 'props'">
                Properties &amp; Leases
            </x-filament::tabs.item>
            @if ($canViewRevenue)
                <x-filament::tabs.item alpine-active="tab === 'revenue'" x-on:click="tab = 'revenue'">
                    Revenue
                </x-filament::tabs.item>
            @endif
        </x-filament::tabs>

        {{-- Overview --}}
        <div x-show="tab === 'overview'" x-cloak style="margin-top: 1.25rem;">
            <x-ah.stat-grid>
                <x-ah.stat label="Total Users" :value="$fmt($counts['total_users'] ?? 0)" />
                <x-ah.stat label="Active Users (30d)" :value="$fmt($counts['active_users'] ?? 0)" />
                <x-ah.stat label="Total Properties" :value="$fmt($counts['total_properties'] ?? 0)" />
                <x-ah.stat label="Active Listings" :value="$fmt($counts['active_listings'] ?? 0)" />
                <x-ah.stat label="Active Leases" :value="$fmt($counts['active_leases'] ?? 0)" />
                <x-ah.stat label="Total Acres" :value="$acres($counts['total_acres'] ?? 0)" />
            </x-ah.stat-grid>
        </div>

        {{-- Users --}}
        <div x-show="tab === 'users'" x-cloak style="margin-top: 1.25rem;">
            <x-ah.stat-grid>
                <x-ah.stat label="Total Users" :value="$fmt($counts['total_users'] ?? 0)" />
                <x-ah.stat label="Active Users (30d)" :value="$fmt($counts['active_users'] ?? 0)" />
                <x-ah.stat label="New Users (30d)" :value="$fmt($counts['new_users_30d'] ?? 0)" />
            </x-ah.stat-grid>

            <x-filament::section heading="Users by Account Type" style="margin-top: 1.25rem;">
                <x-ah.stat-grid>
                    @forelse ($usersByType as $type => $count)
                        <x-ah.stat :label="ucfirst($type)" :value="$fmt($count)" />
                    @empty
                        <p style="opacity:.6;">No users recorded.</p>
                    @endforelse
                </x-ah.stat-grid>
            </x-filament::section>
        </div>

        {{-- Properties & Leases --}}
        <div x-show="tab === 'props'" x-cloak style="margin-top: 1.25rem;">
            <x-ah.stat-grid>
                <x-ah.stat label="Total Properties" :value="$fmt($counts['total_properties'] ?? 0)" />
                <x-ah.stat label="Total Listings" :value="$fmt($counts['total_listings'] ?? 0)" />
                <x-ah.stat label="Active Listings" :value="$fmt($counts['active_listings'] ?? 0)" />
                <x-ah.stat label="Total Acres" :value="$acres($counts['total_acres'] ?? 0)" />
                <x-ah.stat label="Huntable Acres" :value="$acres($counts['huntable_acres'] ?? 0)" />
                <x-ah.stat label="Total Leases" :value="$fmt($counts['total_leases'] ?? 0)" />
            </x-ah.stat-grid>

            <x-filament::section heading="Leases by Status" style="margin-top: 1.25rem;">
                <x-ah.stat-grid>
                    @forelse ($leasesByStatus as $status => $count)
                        <x-ah.stat :label="ucwords(str_replace('_', ' ', $status))" :value="$fmt($count)" />
                    @empty
                        <p style="opacity:.6;">No leases recorded.</p>
                    @endforelse
                </x-ah.stat-grid>
            </x-filament::section>
        </div>

        {{-- Revenue --}}
        @if ($canViewRevenue)
            <div x-show="tab === 'revenue'" x-cloak style="margin-top: 1.25rem;">
                @if ($revenue)
                    <x-ah.stat-grid>
                        <x-ah.stat label="Gross Marketplace Volume" :value="$money($revenue['gmv_cents'])" />
                        <x-ah.stat label="Platform Fees" :value="$money($revenue['platform_fees_cents'])" />
                        <x-ah.stat label="Payouts" :value="$money($revenue['payouts_cents'])" />
                    </x-ah.stat-grid>
                @else
                    <p style="opacity:.6;">No revenue snapshot yet.</p>
                @endif
            </div>
        @endif
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
