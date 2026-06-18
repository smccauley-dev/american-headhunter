<?php

namespace App\Filament\Admin\Resources\MembershipPlans\Pages;

use App\Filament\Admin\Concerns\HasListPageScaffold;
use App\Filament\Admin\Resources\MembershipPlans\MembershipPlanResource;
use App\Support\HasIconPageHeading;
use Filament\Resources\Pages\ListRecords;

class ListMembershipPlans extends ListRecords
{
    use HasIconPageHeading;
    use HasListPageScaffold;

    protected static string $resource = MembershipPlanResource::class;

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return $this->headingWithIcon('Membership Plans', 'heroicon-o-rectangle-stack');
    }
}
