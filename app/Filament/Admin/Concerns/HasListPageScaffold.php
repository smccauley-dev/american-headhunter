<?php

namespace App\Filament\Admin\Concerns;

/**
 * Standard scaffold for ListRecords pages.
 *
 * Enforces the convention that List pages have no header actions.
 * The Add/Create button belongs in the table toolbar (toolbarActions
 * on the resource's table() method), not the page header.
 *
 * Override getHeaderActions() only when adding non-Create page-level
 * actions such as Export.
 */
trait HasListPageScaffold
{
    protected function getHeaderActions(): array
    {
        return [];
    }
}
