<?php

namespace App\Filament\Admin\Resources\EmailTemplates\Pages;

use App\Filament\Admin\Concerns\HasListPageScaffold;
use App\Filament\Admin\Resources\EmailTemplates\EmailTemplateResource;
use App\Support\HasIconPageHeading;
use Filament\Resources\Pages\ListRecords;

class ListEmailTemplates extends ListRecords
{
    use HasIconPageHeading;
    use HasListPageScaffold;

    protected static string $resource = EmailTemplateResource::class;

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return $this->headingWithIcon('Email Templates', 'heroicon-o-envelope-open');
    }
}
