<?php

namespace App\Filament\Admin\Concerns;

/**
 * Standard scaffold for ManageRecords pages.
 *
 * All CRUD on Manage pages is modal-driven. The Create button and bulk
 * actions belong in the table toolbar (toolbarActions on the resource's
 * table() method). No header actions by default.
 *
 * Override getHeaderActions() only when this page needs page-level
 * actions outside the table (e.g. a global Export button).
 */
trait HasManagePageScaffold
{
    protected function getHeaderActions(): array
    {
        return [];
    }
}
