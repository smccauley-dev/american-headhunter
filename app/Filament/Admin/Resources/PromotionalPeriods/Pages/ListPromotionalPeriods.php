<?php

namespace App\Filament\Admin\Resources\PromotionalPeriods\Pages;

use App\Filament\Admin\Concerns\HasListPageScaffold;
use App\Filament\Admin\Resources\PromotionalPeriods\PromotionalPeriodResource;
use App\Support\HasIconPageHeading;
use Filament\Resources\Pages\ListRecords;

class ListPromotionalPeriods extends ListRecords
{
    use HasIconPageHeading;
    use HasListPageScaffold;

    protected static string $resource = PromotionalPeriodResource::class;

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return $this->headingWithIcon('Promotions', 'heroicon-o-gift');
    }
}
