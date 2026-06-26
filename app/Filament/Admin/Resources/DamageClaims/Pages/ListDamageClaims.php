<?php

namespace App\Filament\Admin\Resources\DamageClaims\Pages;

use App\Filament\Admin\Concerns\HasListPageScaffold;
use App\Filament\Admin\Resources\DamageClaims\DamageClaimResource;
use App\Support\HasIconPageHeading;
use Filament\Resources\Pages\ListRecords;

class ListDamageClaims extends ListRecords
{
    use HasIconPageHeading;
    use HasListPageScaffold;

    protected static string $resource = DamageClaimResource::class;

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return $this->headingWithIcon('Damage Claims', 'heroicon-o-wrench-screwdriver');
    }
}
