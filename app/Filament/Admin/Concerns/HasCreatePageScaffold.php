<?php

namespace App\Filament\Admin\Concerns;

use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

/**
 * Standard scaffold for CreateRecord pages.
 *
 * Provides:
 *   - Form footer: Create (+) + Cancel (✕) icons
 *
 * Create pages have no header actions — the Add button lives in the
 * table toolbar (toolbarActions) on the ListRecords page.
 */
trait HasCreatePageScaffold
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
