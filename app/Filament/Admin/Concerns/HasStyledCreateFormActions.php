<?php

namespace App\Filament\Admin\Concerns;

use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

trait HasStyledCreateFormActions
{
    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->icon(Heroicon::OutlinedPlus);
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()
            ->icon(Heroicon::OutlinedXMark);
    }
}
