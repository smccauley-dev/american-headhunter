<?php

namespace App\Filament\Admin\Resources\GameTypes\Pages;

use App\Filament\Admin\Concerns\HasListPageScaffold;
use App\Filament\Admin\Resources\GameTypes\GameTypeResource;
use App\Services\Property\PropertyService;
use App\Support\HasIconPageHeading;
use Filament\Resources\Pages\ListRecords;

class ListGameTypes extends ListRecords
{
    use HasIconPageHeading;
    use HasListPageScaffold;

    protected static string $resource = GameTypeResource::class;

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return $this->headingWithIcon('Game Types', 'heroicon-o-sparkles');
    }

    // Drag-to-reorder writes sort_order directly; drop the cached registry so the
    // new order shows on member pickers and public listings immediately.
    public function reorderTable(array $order, string|int|null $draggedRecordKey = null): void
    {
        parent::reorderTable($order, $draggedRecordKey);

        app(PropertyService::class)->forgetGameTypesCache();
    }
}
