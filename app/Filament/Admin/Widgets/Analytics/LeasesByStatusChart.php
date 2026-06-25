<?php

namespace App\Filament\Admin\Widgets\Analytics;

use App\Services\Analytics\AnalyticsService;
use Filament\Widgets\ChartWidget;

/**
 * Doughnut chart of the lease portfolio by status, from the latest DB 8 snapshot
 * (leases_by_status JSONB).
 */
class LeasesByStatusChart extends ChartWidget
{
    /** Status → brand colour (docs/design_system.md). Falls back to the cycle for unknowns. */
    private const STATUS_COLORS = [
        'active'             => '#6b7856', // sage — healthy
        'pending_signatures' => '#b8934a', // gold — in progress
        'expired'            => '#a89874', // tan — aged out
        'terminated'         => '#8a3216', // clay — ended early
        'cancelled'          => '#722814', // deep rust — cancelled
    ];

    private const FALLBACK = ['#c84c21', '#1c302a', '#4a5440', '#c9b896'];

    protected ?string $heading = 'Leases by status';

    public function getDescription(): ?string
    {
        return 'Current lease portfolio by lifecycle status.';
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $byStatus = app(AnalyticsService::class)->dashboardCounts()['leases_by_status'];

        arsort($byStatus);

        $labels = [];
        $colors = [];
        $i = 0;
        foreach (array_keys($byStatus) as $status) {
            $labels[] = ucwords(str_replace('_', ' ', $status));
            $colors[] = self::STATUS_COLORS[$status] ?? self::FALLBACK[$i++ % count(self::FALLBACK)];
        }

        return [
            'datasets' => [[
                'label'           => 'Leases',
                'data'            => array_values($byStatus),
                'backgroundColor' => $colors,
                'borderColor'     => '#f4ecdc',
                'borderWidth'     => 2,
            ]],
            'labels' => $labels,
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => ['position' => 'right'],
            ],
        ];
    }
}
