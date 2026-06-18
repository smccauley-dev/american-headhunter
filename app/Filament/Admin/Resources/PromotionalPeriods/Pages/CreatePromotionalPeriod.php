<?php

namespace App\Filament\Admin\Resources\PromotionalPeriods\Pages;

use App\Filament\Admin\Concerns\HasCreatePageScaffold;
use App\Filament\Admin\Resources\PromotionalPeriods\PromotionalPeriodResource;
use App\Services\Platform\EntitlementService;
use Filament\Resources\Pages\CreateRecord;

class CreatePromotionalPeriod extends CreateRecord
{
    use HasCreatePageScaffold;

    protected static string $resource = PromotionalPeriodResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by_user_id'] = auth()->id();

        return $data;
    }

    protected function afterCreate(): void
    {
        // A new promotion can change what users resolve to once active.
        app(EntitlementService::class)->invalidateAll();
    }
}
