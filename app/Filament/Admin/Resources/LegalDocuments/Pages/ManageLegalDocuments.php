<?php

namespace App\Filament\Admin\Resources\LegalDocuments\Pages;

use App\Filament\Admin\Concerns\HasManagePageScaffold;
use App\Filament\Admin\Resources\LegalDocuments\LegalDocumentResource;
use App\Support\HasIconPageHeading;
use Filament\Resources\Pages\ManageRecords;

class ManageLegalDocuments extends ManageRecords
{
    use HasIconPageHeading;
    use HasManagePageScaffold;

    protected static string $resource = LegalDocumentResource::class;

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return $this->headingWithIcon('Legal Documents', 'heroicon-o-document-text');
    }
}
