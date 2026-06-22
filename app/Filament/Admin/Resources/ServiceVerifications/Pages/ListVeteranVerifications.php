<?php

namespace App\Filament\Admin\Resources\ServiceVerifications\Pages;

use App\Filament\Admin\Concerns\HasListPageScaffold;
use App\Filament\Admin\Resources\ServiceVerifications\VeteranVerificationResource;
use App\Support\HasIconPageHeading;
use Filament\Resources\Pages\ListRecords;

class ListVeteranVerifications extends ListRecords
{
    use HasIconPageHeading;
    use HasListPageScaffold;

    protected static string $resource = VeteranVerificationResource::class;

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return $this->headingWithIcon('Veteran Verifications', 'heroicon-o-shield-check');
    }
}
