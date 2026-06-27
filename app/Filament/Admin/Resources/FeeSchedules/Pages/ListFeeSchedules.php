<?php

namespace App\Filament\Admin\Resources\FeeSchedules\Pages;

use App\Filament\Admin\Concerns\HasListPageScaffold;
use App\Filament\Admin\Resources\FeeSchedules\FeeScheduleResource;
use App\Support\HasIconPageHeading;
use Filament\Resources\Pages\ListRecords;

class ListFeeSchedules extends ListRecords
{
    use HasIconPageHeading;
    use HasListPageScaffold;

    protected static string $resource = FeeScheduleResource::class;

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return $this->headingWithIcon('Processing Fees', 'heroicon-o-receipt-percent');
    }
}
