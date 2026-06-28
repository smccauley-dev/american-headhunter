<?php

namespace App\Filament\Admin\Resources\GameTypes\Pages;

use App\Filament\Admin\Concerns\HasEditPageScaffold;
use App\Filament\Admin\Resources\GameTypes\GameTypeResource;
use App\Models\Property\GameType;
use App\Services\Property\PropertyService;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditGameType extends EditRecord
{
    use HasEditPageScaffold;

    protected static string $resource = GameTypeResource::class;

    // GameType has no soft deletes, so skip the standard View/Force/Restore set —
    // just a delete guarded against types still referenced by a property.
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->requiresConfirmation()
                ->modalHeading('Delete Game Type')
                ->before(function (GameType $record, DeleteAction $action): void {
                    if (app(PropertyService::class)->gameTypeInUse($record->code)) {
                        Notification::make()
                            ->danger()
                            ->title('Game type in use')
                            ->body('One or more properties still list this game type. Deactivate it instead of deleting.')
                            ->send();

                        $action->halt();
                    }
                })
                ->after(fn () => app(PropertyService::class)->forgetGameTypesCache()),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return GameTypeResource::applyIconNormalization($data);
    }

    protected function afterSave(): void
    {
        app(PropertyService::class)->forgetGameTypesCache();
    }
}
