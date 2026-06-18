<?php

namespace App\Filament\Admin\Resources\PromoCodes\Pages;

use App\Filament\Admin\Concerns\HasManagePageScaffold;
use App\Filament\Admin\Resources\PromoCodes\PromoCodeResource;
use App\Support\HasIconPageHeading;
use Filament\Resources\Pages\ManageRecords;

class ManagePromoCodes extends ManageRecords
{
    use HasIconPageHeading;
    use HasManagePageScaffold;

    protected static string $resource = PromoCodeResource::class;

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return $this->headingWithIcon('Promo Codes', 'heroicon-o-ticket');
    }
}
