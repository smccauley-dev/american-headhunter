<?php

namespace App\Filament\Admin\Widgets\Analytics;

use App\Filament\Admin\Concerns\HasContainedStatsSection;
use App\Services\Analytics\AnalyticsService;
use App\Support\AdminAuth;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Revenue figures (GMV / platform fees / payouts) from DB 8 revenue_snapshots.
 *
 * This is the one widget that reads the restricted analytics_admin (ah_system)
 * connection. It is hidden entirely from admins without billing access — the
 * public/runtime read role can't even SELECT the underlying table, so the gate
 * here is defence-in-depth, not the sole control.
 */
class RevenueStats extends StatsOverviewWidget
{
    use HasContainedStatsSection;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'Revenue';

    public static function canView(): bool
    {
        return AdminAuth::canViewBilling();
    }

    protected function getStats(): array
    {
        $r = app(AnalyticsService::class)->revenue();

        $dollars = fn (?int $cents) => '$' . number_format(((int) $cents) / 100, 2);

        return [
            Stat::make('Gross merchandise value', $dollars($r?->gmv_cents))
                ->description('Total completed payments')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),

            Stat::make('Platform fees', $dollars($r?->platform_fees_cents))
                ->description('Revenue retained by the platform')
                ->descriptionIcon('heroicon-m-receipt-percent')
                ->color('primary'),

            Stat::make('Payouts', $dollars($r?->payouts_cents))
                ->description('Paid out to landowners')
                ->descriptionIcon('heroicon-m-arrow-up-tray')
                ->color('warning'),
        ];
    }
}
