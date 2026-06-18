<?php

namespace App\Filament\Admin\Resources\Payments\Pages;

use App\Filament\Admin\Concerns\HasListPageScaffold;
use App\Filament\Admin\Resources\Payments\PaymentResource;
use App\Support\HasIconPageHeading;
use Filament\Resources\Pages\ListRecords;

class ListPayments extends ListRecords
{
    use HasIconPageHeading;
    use HasListPageScaffold;

    protected static string $resource = PaymentResource::class;

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return $this->headingWithIcon('Payments', 'heroicon-o-credit-card');
    }
}
