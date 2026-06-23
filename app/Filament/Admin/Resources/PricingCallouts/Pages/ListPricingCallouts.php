<?php

namespace App\Filament\Admin\Resources\PricingCallouts\Pages;

use App\Filament\Admin\Concerns\HasListPageScaffold;
use App\Filament\Admin\Resources\PricingCallouts\PricingCalloutResource;
use App\Support\HasIconPageHeading;
use Filament\Resources\Pages\ListRecords;

class ListPricingCallouts extends ListRecords
{
    use HasIconPageHeading;
    use HasListPageScaffold;

    protected static string $resource = PricingCalloutResource::class;

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return $this->headingWithIcon('Pricing Callouts', 'heroicon-o-megaphone');
    }
}
