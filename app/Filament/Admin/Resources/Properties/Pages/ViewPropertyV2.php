<?php

namespace App\Filament\Admin\Resources\Properties\Pages;

use App\Filament\Admin\Concerns\HasViewPageScaffold;
use App\Filament\Admin\Resources\Properties\PropertyResource;
use App\Filament\Admin\Resources\Properties\Schemas\PropertyInfolistV2;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

class ViewPropertyV2 extends ViewRecord
{
    use HasViewPageScaffold;

    protected static string $resource = PropertyResource::class;

    public function infolist(Schema $schema): Schema
    {
        return PropertyInfolistV2::configure($schema);
    }

    public function getRelationManagers(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        return $this->standardViewHeaderActions();
    }
}
