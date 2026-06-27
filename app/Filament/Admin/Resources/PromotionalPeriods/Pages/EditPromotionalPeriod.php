<?php

namespace App\Filament\Admin\Resources\PromotionalPeriods\Pages;

use App\Filament\Admin\Concerns\HasEditPageScaffold;
use App\Filament\Admin\Concerns\SyncsPromotionCoupon;
use App\Filament\Admin\Resources\PromotionalPeriods\PromotionalPeriodResource;
use Filament\Resources\Pages\EditRecord;

class EditPromotionalPeriod extends EditRecord
{
    use HasEditPageScaffold;
    use SyncsPromotionCoupon;

    protected static string $resource = PromotionalPeriodResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function afterSave(): void
    {
        $this->invalidateEntitlements();
        // Mirror the discount to a Stripe Coupon so an active promo actually
        // discounts checkout — otherwise the code validates but charges full price.
        $this->syncPromotionCoupon($this->record);
    }
}
