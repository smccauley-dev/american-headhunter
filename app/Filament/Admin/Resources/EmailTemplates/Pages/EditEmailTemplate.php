<?php

namespace App\Filament\Admin\Resources\EmailTemplates\Pages;

use App\Filament\Admin\Concerns\HasEditPageScaffold;
use App\Filament\Admin\Resources\EmailTemplates\EmailTemplateResource;
use App\Models\Communications\EmailTemplate;
use App\Services\Communications\EmailTemplateService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditEmailTemplate extends EditRecord
{
    use HasEditPageScaffold;

    protected static string $resource = EmailTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('deleteTemplate')
                ->label('Delete')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Delete Email Template')
                ->modalDescription('The template and all of its versions will no longer be available. System templates cannot be deleted.')
                ->visible(fn (): bool => ! $this->getRecord()->isSystem())
                ->action(function (): void {
                    /** @var EmailTemplate $record */
                    $record = $this->getRecord();

                    try {
                        app(EmailTemplateService::class)->deleteTemplate($record->id);
                    } catch (\InvalidArgumentException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title('Email template deleted')->success()->send();

                    $this->redirect($this->getResource()::getUrl('index'));
                }),
        ];
    }
}
