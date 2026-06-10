<?php

namespace App\Filament\Admin\Resources\Applications\Pages;

use App\Filament\Admin\Concerns\HasListPageScaffold;
use App\Filament\Admin\Resources\Applications\LeaseApplicationResource;
use App\Support\HasIconPageHeading;
use Filament\Resources\Pages\ListRecords;

class ListLeaseApplications extends ListRecords
{
    use HasIconPageHeading;
    use HasListPageScaffold;

    protected static string $resource = LeaseApplicationResource::class;

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return $this->headingWithIcon('Lease Applications', 'heroicon-o-clipboard-document-list');
    }
}
