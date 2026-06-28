<?php

namespace App\Filament\Admin\Resources\GameTypes\Pages;

use App\Filament\Admin\Concerns\HasCreatePageScaffold;
use App\Filament\Admin\Resources\GameTypes\GameTypeResource;
use App\Services\Property\PropertyService;
use Filament\Resources\Pages\CreateRecord;

class CreateGameType extends CreateRecord
{
    use HasCreatePageScaffold;

    protected static string $resource = GameTypeResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return GameTypeResource::applyIconNormalization($data);
    }

    protected function afterCreate(): void
    {
        app(PropertyService::class)->forgetGameTypesCache();
    }
}
