<?php

namespace App\Filament\Admin\Resources\Invoices\Pages;

use App\Filament\Admin\Concerns\HasListPageScaffold;
use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Support\HasIconPageHeading;
use Filament\Resources\Pages\ListRecords;

class ListInvoices extends ListRecords
{
    use HasIconPageHeading;
    use HasListPageScaffold;

    protected static string $resource = InvoiceResource::class;

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return $this->headingWithIcon('Invoices', 'heroicon-o-document-text');
    }
}
