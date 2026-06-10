<?php

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Filament\Admin\Concerns\HasListPageScaffold;
use App\Filament\Admin\Resources\Users\AdminUserResource;
use App\Support\HasIconPageHeading;
use Filament\Resources\Pages\ListRecords;

class ListAdminUsers extends ListRecords
{
    use HasIconPageHeading;
    use HasListPageScaffold;

    protected static string $resource = AdminUserResource::class;

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return $this->headingWithIcon('Admin Users', 'heroicon-o-users');
    }
}
