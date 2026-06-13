<?php

namespace App\Filament\Admin\Resources\ProfileTemplates\Pages;

use App\Filament\Admin\Concerns\HasListPageScaffold;
use App\Filament\Admin\Resources\ProfileTemplates\ProfileTemplateResource;
use App\Support\HasIconPageHeading;
use Filament\Resources\Pages\ListRecords;

class ListProfileTemplates extends ListRecords
{
    use HasIconPageHeading;
    use HasListPageScaffold;

    protected static string $resource = ProfileTemplateResource::class;

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return $this->headingWithIcon('Profile Templates', 'heroicon-o-swatch');
    }
}
