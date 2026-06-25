<?php

namespace App\Filament\Admin\Widgets\Analytics;

use App\Services\Analytics\AnalyticsService;
use Filament\Widgets\ChartWidget;

/**
 * Pie chart of the user base broken out by account type, from the latest DB 8
 * snapshot (users_by_type JSONB).
 */
class UsersByTypeChart extends ChartWidget
{
    /** Brand palette (docs/design_system.md) — rust, gold, sage, forest, clay, tan, olive, cream. */
    private const PALETTE = [
        '#c84c21', '#b8934a', '#6b7856', '#1c302a',
        '#8a3216', '#a89874', '#4a5440', '#c9b896',
    ];

    protected ?string $heading = 'Users by account type';

    public function getDescription(): ?string
    {
        return 'Share of the user base by signup type.';
    }

    protected function getType(): string
    {
        return 'pie';
    }

    protected function getData(): array
    {
        $byType = app(AnalyticsService::class)->dashboardCounts()['users_by_type'];

        arsort($byType);

        $labels = array_map(fn ($k) => ucfirst($k), array_keys($byType));
        $values = array_values($byType);

        return [
            'datasets' => [[
                'label'           => 'Users',
                'data'            => $values,
                'backgroundColor' => array_slice(self::PALETTE, 0, max(1, count($values))),
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
