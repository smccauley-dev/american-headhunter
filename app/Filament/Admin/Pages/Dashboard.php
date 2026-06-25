<?php

namespace App\Filament\Admin\Pages;

use App\Jobs\Etl\SyncPlatformSnapshot;
use App\Services\Analytics\AnalyticsService;
use App\Support\AdminAuth;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * Admin home — platform analytics, tabbed (Overview / Users / Properties & Leases
 * / Revenue). All figures come pre-computed from DB 8 via AnalyticsService, so the
 * page reads instantly and never fans out across the transactional databases.
 *
 * Counts/acres come from platform_snapshots (ah_readonly). Revenue comes from
 * revenue_snapshots through the restricted analytics_admin (ah_system) connection
 * and is additionally gated to billing-capable admins — ah_readonly can't read
 * that table at all, so the figures never reach a lesser-privileged path.
 */
class Dashboard extends BaseDashboard
{
    /** @var array<string,mixed> */
    public array $counts = [];

    /** @var array<string,int>|null */
    public ?array $revenue = null;

    public ?string $capturedAtHuman = null;

    public bool $canViewRevenue = false;

    public function mount(): void
    {
        $this->loadAnalytics();
    }

    public function getView(): string
    {
        return 'filament.admin.pages.dashboard';
    }

    public function getTitle(): string
    {
        return 'Dashboard';
    }

    private function loadAnalytics(): void
    {
        $service = app(AnalyticsService::class);

        $this->counts          = $service->dashboardCounts();
        $this->capturedAtHuman = $this->counts['captured_at']?->diffForHumans();
        $this->canViewRevenue  = AdminAuth::canViewBilling();

        if ($this->canViewRevenue) {
            $revenue = $service->revenue();
            $this->revenue = $revenue ? [
                'gmv_cents'           => (int) $revenue->gmv_cents,
                'platform_fees_cents' => (int) $revenue->platform_fees_cents,
                'payouts_cents'       => (int) $revenue->payouts_cents,
            ] : null;
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Refresh now')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(function () {
                    // Same job the hourly scheduler runs; synchronous so the page
                    // shows fresh figures on this render.
                    (new SyncPlatformSnapshot)->handle();
                    $this->loadAnalytics();

                    Notification::make()
                        ->title('Analytics refreshed')
                        ->success()
                        ->send();
                }),
        ];
    }
}
