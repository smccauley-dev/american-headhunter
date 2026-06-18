<?php

namespace App\Filament\Admin\Resources\Invoices\Pages;

use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Support\HasIconPageHeading;
use Filament\Resources\Pages\ViewRecord;

class ViewInvoice extends ViewRecord
{
    use HasIconPageHeading;

    protected static string $resource = InvoiceResource::class;

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return $this->headingWithIcon('Invoice', 'heroicon-o-document-text');
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
