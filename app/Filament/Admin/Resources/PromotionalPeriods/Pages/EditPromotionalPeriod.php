<?php

namespace App\Filament\Admin\Resources\PromotionalPeriods\Pages;

use App\Filament\Admin\Concerns\HasEditPageScaffold;
use App\Filament\Admin\Resources\PromotionalPeriods\PromotionalPeriodResource;
use App\Services\Platform\EntitlementService;
use Filament\Resources\Pages\EditRecord;

class EditPromotionalPeriod extends EditRecord
{
    use HasEditPageScaffold;

    protected static string $resource = PromotionalPeriodResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function afterSave(): void
    {
        // Terms or status changes can alter active claimants' entitlements.
        app(EntitlementService::class)->invalidateAll();
    }
}
