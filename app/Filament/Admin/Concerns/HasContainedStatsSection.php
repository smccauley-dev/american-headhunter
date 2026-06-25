<?php

namespace App\Filament\Admin\Concerns;

use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;

/**
 * Dashboard scaffold for StatsOverviewWidget cards.
 *
 * Filament's StatsOverviewWidget renders its stats inside a Section pinned to
 * ->contained(false), which strips the section's inner padding. Under this
 * project's Field-Record section chrome (parchment card + inset dashed border,
 * AdminPanelProvider CSS) that makes the stat cards bleed to the dashed edge and
 * pushes the heading flush against the border.
 *
 * Overriding the section component to drop contained(false) restores the standard
 * ~24px inner padding, so the heading and cards sit inside the dashed border like
 * every other section on the admin panel. See docs/filament_page_template.md.
 */
trait HasContainedStatsSection
{
    public function getSectionContentComponent(): Component
    {
        return Section::make()
            ->heading($this->getHeading())
            ->description($this->getDescription())
            ->schema($this->getCachedStats())
            ->columns($this->getColumns())
            ->gridContainer();
    }
}
