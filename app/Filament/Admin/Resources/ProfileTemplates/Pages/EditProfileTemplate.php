<?php

namespace App\Filament\Admin\Resources\ProfileTemplates\Pages;

use App\Filament\Admin\Concerns\HasEditPageScaffold;
use App\Filament\Admin\Resources\ProfileTemplates\ProfileTemplateResource;
use App\Models\Platform\ProfileTemplate;
use App\Services\Platform\ProfileTemplateService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditProfileTemplate extends EditRecord
{
    use HasEditPageScaffold;

    protected static string $resource = ProfileTemplateResource::class;

    // Backfill any missing keys from the defaults so the form always renders fully.
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['draft_config'] = app(ProfileTemplateService::class)
            ->getDraftConfig($this->getRecord()->profile_type);

        return $data;
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Draft saved — Publish to make it live.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('publish')
                ->label('Publish')
                ->icon(Heroicon::OutlinedRocketLaunch)
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Publish Profile Template')
                ->modalDescription('This makes the current draft live for every profile of this type.')
                ->action(function (): void {
                    // Persist the current form (draft) first, then promote it.
                    $this->save(shouldRedirect: false, shouldSendSavedNotification: false);

                    /** @var ProfileTemplate $record */
                    $record = $this->getRecord();

                    app(ProfileTemplateService::class)->publish($record->profile_type, auth()->id());

                    Notification::make()->title('Profile template published')->success()->send();
                }),
        ];
    }
}
