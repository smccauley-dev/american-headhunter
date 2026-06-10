<?php

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Filament\Admin\Concerns\HasListPageScaffold;
use App\Filament\Admin\Resources\Users\CustomerUserResource;
use App\Support\HasIconPageHeading;
use Filament\Resources\Pages\ListRecords;

class ListCustomerUsers extends ListRecords
{
    use HasIconPageHeading;
    use HasListPageScaffold;

    protected static string $resource = CustomerUserResource::class;

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return $this->headingWithIcon('Platform Users', 'heroicon-o-user-group');
    }
}
