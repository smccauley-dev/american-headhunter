<?php

namespace App\Filament\Admin\Resources\Amenities\Pages;

use App\Filament\Admin\Concerns\HasManagePageScaffold;
use App\Filament\Admin\Resources\Amenities\PropertyAmenityResource;
use App\Support\HasIconPageHeading;
use Filament\Resources\Pages\ManageRecords;

class ManagePropertyAmenities extends ManageRecords
{
    use HasIconPageHeading;
    use HasManagePageScaffold;

    protected static string $resource = PropertyAmenityResource::class;

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return $this->headingWithIcon('Property Amenities', 'heroicon-o-tag');
    }
}
