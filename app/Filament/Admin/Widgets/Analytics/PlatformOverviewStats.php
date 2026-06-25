<?php

namespace App\Filament\Admin\Widgets\Analytics;

use App\Services\Analytics\AnalyticsService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Headline platform counters across the top of the admin dashboard. Reads the
 * latest public-safe snapshot from DB 8 (platform_snapshots / ah_readonly) — no
 * revenue here (see RevenueStats for that, gated to billing admins).
 */
class PlatformOverviewStats extends StatsOverviewWidget
{
    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'Platform overview';

    protected function getStats(): array
    {
        $c = app(AnalyticsService::class)->dashboardCounts();

        return [
            Stat::make('Total users', number_format($c['total_users']))
                ->description(number_format($c['active_users']) . ' active in last 30 days')
                ->descriptionIcon('heroicon-m-users')
                ->color('primary'),

            Stat::make('New users (30d)', number_format($c['new_users_30d']))
                ->description('Joined in the last 30 days')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success'),

            Stat::make('Properties', number_format($c['total_properties']))
                ->description(number_format($c['active_listings']) . ' active listings')
                ->descriptionIcon('heroicon-m-map')
                ->color('warning'),

            Stat::make('Active leases', number_format($c['active_leases']))
                ->description('of ' . number_format($c['total_leases']) . ' total')
                ->descriptionIcon('heroicon-m-document-check')
                ->color('primary'),

            Stat::make('Total acres', number_format($c['total_acres']))
                ->description(number_format($c['huntable_acres']) . ' huntable')
                ->descriptionIcon('heroicon-m-globe-americas')
                ->color('success'),
        ];
    }
}
