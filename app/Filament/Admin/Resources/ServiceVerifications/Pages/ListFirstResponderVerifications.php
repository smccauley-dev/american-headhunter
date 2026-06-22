<?php

namespace App\Filament\Admin\Resources\ServiceVerifications\Pages;

use App\Filament\Admin\Concerns\HasListPageScaffold;
use App\Filament\Admin\Resources\ServiceVerifications\FirstResponderVerificationResource;
use App\Support\HasIconPageHeading;
use Filament\Resources\Pages\ListRecords;

class ListFirstResponderVerifications extends ListRecords
{
    use HasIconPageHeading;
    use HasListPageScaffold;

    protected static string $resource = FirstResponderVerificationResource::class;

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return $this->headingWithIcon('First Responder Verifications', 'heroicon-o-check-badge');
    }
}
