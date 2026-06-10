<?php

namespace App\Filament\Admin\Concerns;

use App\Support\AdminAuth;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;

/**
 * Standard scaffold for EditRecord pages.
 *
 * Provides:
 *   - Form footer: Save Changes (✓) + Cancel (✕) icons
 *   - standardHeaderActions(): [View, Delete, ForceDelete (super_admin), Restore]
 *
 * Usage in getHeaderActions():
 *   return $this->standardHeaderActions();
 *   // or add resource-specific actions:
 *   return [...$this->standardHeaderActions(), PrintAction::make()];
 */
trait HasEditPageScaffold
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

    protected function standardHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
            ForceDeleteAction::make()
                ->visible(fn () => AdminAuth::isSuperAdmin()),
            RestoreAction::make(),
        ];
    }
}
