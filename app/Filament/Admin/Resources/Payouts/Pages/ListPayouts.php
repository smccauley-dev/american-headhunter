<?php

namespace App\Filament\Admin\Resources\Payouts\Pages;

use App\Filament\Admin\Concerns\HasListPageScaffold;
use App\Filament\Admin\Resources\Payouts\PayoutResource;
use App\Support\HasIconPageHeading;
use Filament\Resources\Pages\ListRecords;

class ListPayouts extends ListRecords
{
    use HasIconPageHeading;
    use HasListPageScaffold;

    protected static string $resource = PayoutResource::class;

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return $this->headingWithIcon('Payouts', 'heroicon-o-banknotes');
    }
}
