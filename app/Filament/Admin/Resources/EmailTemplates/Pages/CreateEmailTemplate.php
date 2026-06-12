<?php

namespace App\Filament\Admin\Resources\EmailTemplates\Pages;

use App\Filament\Admin\Concerns\HasCreatePageScaffold;
use App\Filament\Admin\Resources\EmailTemplates\EmailTemplateResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEmailTemplate extends CreateRecord
{
    use HasCreatePageScaffold;

    protected static string $resource = EmailTemplateResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['category'] = 'custom';

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        // Straight to the edit page so the first version can be added.
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
