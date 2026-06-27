<?php

namespace App\Filament\Admin\Resources\FeeSchedules\Pages;

use App\Filament\Admin\Concerns\HasEditPageScaffold;
use App\Filament\Admin\Resources\FeeSchedules\FeeScheduleResource;
use App\Services\Billing\FeeService;
use Filament\Resources\Pages\EditRecord;

class EditFeeSchedule extends EditRecord
{
    use HasEditPageScaffold;

    protected static string $resource = FeeScheduleResource::class;

    // FeeService caches resolutions for 30 min — drop them on save so a changed
    // rate or activation toggle applies on the next checkout.
    protected function afterSave(): void
    {
        app(FeeService::class)->flushCache();
    }
}
