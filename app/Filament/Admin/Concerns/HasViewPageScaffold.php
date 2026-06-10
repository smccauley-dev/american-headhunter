<?php

namespace App\Filament\Admin\Concerns;

use App\Support\AdminAuth;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;

/**
 * Standard scaffold for ViewRecord pages.
 *
 * Provides:
 *   - standardViewHeaderActions(): [Edit, Delete, ForceDelete (super_admin), Restore]
 *
 * Resources with custom actions (Approve, Reject, Print, etc.) define
 * getHeaderActions() directly — call standardViewHeaderActions() and merge,
 * or skip it entirely for fully custom action sets.
 *
 * Usage in getHeaderActions():
 *   return $this->standardViewHeaderActions();
 *   // or add resource-specific actions:
 *   return [...$this->standardViewHeaderActions(), PrintAction::make()];
 *   // or fully custom (no standard actions):
 *   return [ApproveAction::make(), RejectAction::make()];
 */
trait HasViewPageScaffold
{
    protected function standardViewHeaderActions(): array
    {
        return [
            EditAction::make(),
            DeleteAction::make(),
            ForceDeleteAction::make()
                ->visible(fn () => AdminAuth::isSuperAdmin()),
            RestoreAction::make(),
        ];
    }
}
