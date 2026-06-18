<?php

namespace App\Filament\Admin\Resources\Payouts\Pages;

use App\Filament\Admin\Resources\Payouts\PayoutResource;
use App\Support\HasIconPageHeading;
use Filament\Resources\Pages\ViewRecord;

class ViewPayout extends ViewRecord
{
    use HasIconPageHeading;

    protected static string $resource = PayoutResource::class;

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return $this->headingWithIcon('Payout', 'heroicon-o-banknotes');
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
