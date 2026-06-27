<?php

namespace App\Filament\Admin\Resources\FeeSchedules\Pages;

use App\Filament\Admin\Concerns\HasCreatePageScaffold;
use App\Filament\Admin\Resources\FeeSchedules\FeeScheduleResource;
use App\Services\Billing\FeeService;
use Filament\Resources\Pages\CreateRecord;

class CreateFeeSchedule extends CreateRecord
{
    use HasCreatePageScaffold;

    protected static string $resource = FeeScheduleResource::class;

    // Drop FeeService's cached resolutions so the new rule applies immediately.
    protected function afterCreate(): void
    {
        app(FeeService::class)->flushCache();
    }
}
