<?php

namespace App\Filament\Admin\Resources\LeaseDisputes\Pages;

use App\Filament\Admin\Concerns\HasListPageScaffold;
use App\Filament\Admin\Resources\LeaseDisputes\LeaseDisputeResource;
use App\Support\HasIconPageHeading;
use Filament\Resources\Pages\ListRecords;

class ListLeaseDisputes extends ListRecords
{
    use HasIconPageHeading;
    use HasListPageScaffold;

    protected static string $resource = LeaseDisputeResource::class;

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return $this->headingWithIcon('Lease Disputes', 'heroicon-o-scale');
    }
}
