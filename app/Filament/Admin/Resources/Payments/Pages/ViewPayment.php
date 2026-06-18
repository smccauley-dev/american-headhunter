<?php

namespace App\Filament\Admin\Resources\Payments\Pages;

use App\Filament\Admin\Resources\Payments\PaymentResource;
use App\Support\HasIconPageHeading;
use Filament\Resources\Pages\ViewRecord;

class ViewPayment extends ViewRecord
{
    use HasIconPageHeading;

    protected static string $resource = PaymentResource::class;

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return $this->headingWithIcon('Payment', 'heroicon-o-credit-card');
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
