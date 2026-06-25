<?php

namespace App\Filament\Admin\Resources\PricingCallouts\Pages;

use App\Filament\Admin\Concerns\HasEditPageScaffold;
use App\Filament\Admin\Resources\PricingCallouts\PricingCalloutResource;
use App\Services\Platform\PlanService;
use Filament\Resources\Pages\EditRecord;

class EditPricingCallout extends EditRecord
{
    use HasEditPageScaffold;

    protected static string $resource = PricingCalloutResource::class;

    // The public pricing page caches its payload for 15 min — drop it on save so
    // copy edits and the publish toggle show up immediately.
    protected function afterSave(): void
    {
        app(PlanService::class)->flushPricingCache();
    }
}
