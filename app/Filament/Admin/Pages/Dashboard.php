<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Widgets\Analytics\LeasesByStatusChart;
use App\Filament\Admin\Widgets\Analytics\PlatformOverviewStats;
use App\Filament\Admin\Widgets\Analytics\RevenueStats;
use App\Filament\Admin\Widgets\Analytics\UsersByTypeChart;
use App\Jobs\Etl\SyncPlatformSnapshot;
use App\Services\Analytics\AnalyticsService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * Admin home — platform analytics rendered as Filament widgets (stat cards + pie
 * charts). Every figure is pre-computed in DB 8 and read via AnalyticsService, so
 * the page never fans out across the transactional databases at request time.
 *
 * Counts/acres come from platform_snapshots (ah_readonly); the revenue widget
 * reads revenue_snapshots through the restricted analytics_admin (ah_system)
 * connection and is hidden from admins without billing access (RevenueStats::canView).
 */
class Dashboard extends BaseDashboard
{
    public function getWidgets(): array
    {
        return [
            PlatformOverviewStats::class,
            UsersByTypeChart::class,
            LeasesByStatusChart::class,
            RevenueStats::class,
        ];
    }

    public function getColumns(): int|array
    {
        return 2;
    }

    public function getSubheading(): ?string
    {
        $capturedAt = app(AnalyticsService::class)->current()?->captured_at;

        return $capturedAt
            ? 'Platform analytics · updated ' . $capturedAt->diffForHumans()
            : 'No analytics yet — use “Refresh now” to compute the first snapshot.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Refresh now')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(function () {
                    // Same job the hourly scheduler runs; synchronous so the
                    // widgets re-read fresh figures on the Livewire re-render.
                    (new SyncPlatformSnapshot)->handle();

                    Notification::make()
                        ->title('Analytics refreshed')
                        ->success()
                        ->send();
                }),
        ];
    }
}
