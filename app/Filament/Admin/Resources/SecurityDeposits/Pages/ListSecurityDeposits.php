<?php

namespace App\Filament\Admin\Resources\SecurityDeposits\Pages;

use App\Filament\Admin\Concerns\HasListPageScaffold;
use App\Filament\Admin\Resources\SecurityDeposits\SecurityDepositResource;
use App\Support\HasIconPageHeading;
use Filament\Resources\Pages\ListRecords;

class ListSecurityDeposits extends ListRecords
{
    use HasIconPageHeading;
    use HasListPageScaffold;

    protected static string $resource = SecurityDepositResource::class;

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return $this->headingWithIcon('Security Deposits', 'heroicon-o-banknotes');
    }
}
