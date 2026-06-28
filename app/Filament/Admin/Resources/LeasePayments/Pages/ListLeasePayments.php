<?php

namespace App\Filament\Admin\Resources\LeasePayments\Pages;

use App\Filament\Admin\Concerns\HasListPageScaffold;
use App\Filament\Admin\Resources\LeasePayments\LeasePaymentResource;
use App\Support\HasIconPageHeading;
use Filament\Resources\Pages\ListRecords;

class ListLeasePayments extends ListRecords
{
    use HasIconPageHeading;
    use HasListPageScaffold;

    protected static string $resource = LeasePaymentResource::class;

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return $this->headingWithIcon('Lease Payments', 'heroicon-o-banknotes');
    }
}
