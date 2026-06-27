<?php

namespace App\Filament\Admin\Resources\PromotionalPeriods\Pages;

use App\Filament\Admin\Concerns\HasCreatePageScaffold;
use App\Filament\Admin\Concerns\SyncsPromotionCoupon;
use App\Filament\Admin\Resources\PromotionalPeriods\PromotionalPeriodResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePromotionalPeriod extends CreateRecord
{
    use HasCreatePageScaffold;
    use SyncsPromotionCoupon;

    protected static string $resource = PromotionalPeriodResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by_user_id'] = auth()->id();

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->invalidateEntitlements();
        // If the promotion is created already-active, mirror its discount to a
        // Stripe Coupon now so checkout discounts immediately.
        $this->syncPromotionCoupon($this->record);
    }
}
