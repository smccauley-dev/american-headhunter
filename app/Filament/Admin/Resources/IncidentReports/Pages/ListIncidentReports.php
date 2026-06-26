<?php

namespace App\Filament\Admin\Resources\IncidentReports\Pages;

use App\Filament\Admin\Concerns\HasListPageScaffold;
use App\Filament\Admin\Resources\IncidentReports\IncidentReportResource;
use App\Support\HasIconPageHeading;
use Filament\Resources\Pages\ListRecords;

class ListIncidentReports extends ListRecords
{
    use HasIconPageHeading;
    use HasListPageScaffold;

    protected static string $resource = IncidentReportResource::class;

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return $this->headingWithIcon('Incident Reports', 'heroicon-o-exclamation-triangle');
    }
}
