<?php

namespace App\Filament\Admin\Resources\PricingCallouts\Pages;

use App\Filament\Admin\Concerns\HasCreatePageScaffold;
use App\Filament\Admin\Resources\PricingCallouts\PricingCalloutResource;
use App\Services\Platform\PlanService;
use Filament\Resources\Pages\CreateRecord;

class CreatePricingCallout extends CreateRecord
{
    use HasCreatePageScaffold;

    protected static string $resource = PricingCalloutResource::class;

    // Drop the cached pricing payload so a new published callout appears at once.
    protected function afterCreate(): void
    {
        app(PlanService::class)->flushPricingCache();
    }
}
