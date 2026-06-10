<?php

namespace App\Filament\Admin\Concerns;

use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

trait HasStyledEditFormActions
{
    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()
            ->icon(Heroicon::OutlinedCheckCircle);
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()
            ->icon(Heroicon::OutlinedXMark);
    }
}
