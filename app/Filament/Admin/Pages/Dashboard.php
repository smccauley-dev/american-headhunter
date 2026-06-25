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
 * Admin home — a tabbed dashboard. The first tab renders the platform analytics
 * widgets (stat cards + pie charts, all pre-computed in DB 8 and read via
 * AnalyticsService). The remaining tabs are placeholders we'll build out.
 *
 * The tab bar uses the standard `.fi-tabs` chrome (see AdminPanelProvider CSS),
 * driven by the `$activeTab` Livewire property; getWidgets() returns the widget
 * set for the active tab. Revenue stays gated to billing admins (RevenueStats::canView).
 */
class Dashboard extends BaseDashboard
{
    /** Active tab key. Tabs are declared in tabs(). */
    public ?string $activeTab = 'analytics';

    public function getView(): string
    {
        return 'filament.admin.pages.dashboard';
    }

    /**
     * Tab definitions: key => label. Add a case to getWidgets() (or render
     * placeholder content in the view) when filling one out.
     *
     * @return array<string, string>
     */
    public function tabs(): array
    {
        return [
            'analytics' => 'Platform Analytics',
            'test1'     => 'Test Tab 1',
            'test2'     => 'Test Tab 2',
        ];
    }

    public function getWidgets(): array
    {
        return match ($this->activeTab) {
            'analytics' => [
                PlatformOverviewStats::class,
                UsersByTypeChart::class,
                LeasesByStatusChart::class,
                RevenueStats::class,
            ],
            default => [],
        };
    }

    public function getColumns(): int|array
    {
        return 2;
    }

    /** Status line for the analytics toolbar (e.g. "Updated 1 minute ago"). */
    public function capturedAtLabel(): ?string
    {
        $capturedAt = app(AnalyticsService::class)->current()?->captured_at;

        return $capturedAt
            ? 'Updated ' . $capturedAt->diffForHumans()
            : 'No snapshot yet — refresh to compute the first one';
    }

    /**
     * Recompute the analytics snapshot. Rendered in the analytics toolbar (not
     * the page header) so it can sit alongside future actions like Export Data.
     */
    public function refreshAction(): Action
    {
        return Action::make('refresh')
            ->label('Refresh now')
            ->icon('heroicon-o-arrow-path')
            ->button()
            ->size(\Filament\Support\Enums\Size::Small)
            ->color('gray')
            ->action(function () {
                // Same job the hourly scheduler runs; synchronous so the widgets
                // re-read fresh figures on the Livewire re-render.
                (new SyncPlatformSnapshot)->handle();

                Notification::make()
                    ->title('Analytics refreshed')
                    ->success()
                    ->send();
            });
    }
}
