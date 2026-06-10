<?php

namespace App\Filament\Admin\Resources\Properties\Pages;

use App\Filament\Admin\Concerns\HasEditPageScaffold;
use App\Filament\Admin\Resources\Properties\PropertyResource;
use Filament\Resources\Pages\EditRecord;

class EditProperty extends EditRecord
{
    use HasEditPageScaffold;

    protected static string $resource = PropertyResource::class;

    protected function getHeaderActions(): array
    {
        return $this->standardHeaderActions();
    }
}
