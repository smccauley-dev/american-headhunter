<?php

namespace App\Filament\Admin\Resources\FeatureFlags\Pages;

use App\Filament\Admin\Concerns\HasManagePageScaffold;
use App\Filament\Admin\Resources\FeatureFlags\FeatureFlagResource;
use App\Support\HasIconPageHeading;
use Filament\Resources\Pages\ManageRecords;

class ManageFeatureFlags extends ManageRecords
{
    use HasIconPageHeading;
    use HasManagePageScaffold;

    protected static string $resource = FeatureFlagResource::class;

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return $this->headingWithIcon('Feature Flags', 'heroicon-o-flag');
    }
}
