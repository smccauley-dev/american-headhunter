<?php

namespace App\Filament\Admin\Resources\Properties\Pages;

use App\Filament\Admin\Concerns\HasListPageScaffold;
use App\Filament\Admin\Resources\Properties\PropertyResource;
use App\Support\HasIconPageHeading;
use Filament\Resources\Pages\ListRecords;

class ListProperties extends ListRecords
{
    use HasIconPageHeading;
    use HasListPageScaffold;

    protected static string $resource = PropertyResource::class;

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return $this->headingWithIcon('Properties', 'heroicon-o-map');
    }
}
