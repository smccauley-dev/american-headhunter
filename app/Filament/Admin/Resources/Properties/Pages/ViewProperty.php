<?php

namespace App\Filament\Admin\Resources\Properties\Pages;

use App\Filament\Admin\Concerns\HasViewPageScaffold;
use App\Filament\Admin\Resources\Properties\PropertyResource;
use Filament\Resources\Pages\ViewRecord;

class ViewProperty extends ViewRecord
{
    use HasViewPageScaffold;

    protected static string $resource = PropertyResource::class;

    protected function getHeaderActions(): array
    {
        return $this->standardViewHeaderActions();
    }
}
