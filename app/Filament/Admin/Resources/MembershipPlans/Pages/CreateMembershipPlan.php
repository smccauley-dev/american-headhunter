<?php

namespace App\Filament\Admin\Resources\MembershipPlans\Pages;

use App\Filament\Admin\Concerns\ConvertsPlanPrices;
use App\Filament\Admin\Concerns\HasCreatePageScaffold;
use App\Filament\Admin\Resources\MembershipPlans\MembershipPlanResource;
use Filament\Resources\Pages\CreateRecord;

class CreateMembershipPlan extends CreateRecord
{
    use ConvertsPlanPrices;
    use HasCreatePageScaffold;

    protected static string $resource = MembershipPlanResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->dollarsToCents($data);
    }
}
