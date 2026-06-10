<?php

namespace App\Filament\Admin\Resources\MfaFactorSettings\Pages;

use App\Filament\Admin\Concerns\HasManagePageScaffold;
use App\Filament\Admin\Resources\MfaFactorSettings\MfaFactorSettingResource;
use App\Support\HasIconPageHeading;
use Filament\Resources\Pages\ManageRecords;

class ManageMfaFactorSettings extends ManageRecords
{
    use HasIconPageHeading;
    use HasManagePageScaffold;

    protected static string $resource = MfaFactorSettingResource::class;

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return $this->headingWithIcon('MFA Factor Settings', 'heroicon-o-shield-check');
    }
}
