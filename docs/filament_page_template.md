# Filament Page Template

Every Filament admin page in this project uses one of five scaffold traits.
**Load this file before building any new resource or page.**

---

## Action Zones — All Page Types

```
┌──────────────────────────────────────────────────────────────┐
│ Page Title / Breadcrumb     [action] [action] [action]       │  ← top-right: header actions
├──────────────────────────────────────────────────────────────┤
│                                                              │
│  Content  (form / infolist / table)                          │
│                                                              │
├──────────────────────────────────────────────────────────────┤
│ [PREVIOUS]  [1]  [2]  [3]  [NEXT]          [10 per page ▾]  │  ← table footer: pagination (sandwich)
├──────────────────────────────────────────────────────────────┤
│ [Save Changes ✓]  [Cancel ✕]                                 │  ← bottom-left: form footer (Edit/Create only)
└──────────────────────────────────────────────────────────────┘

Modal:
┌──────────────────────────────────────┐
│ Modal Title                      [×] │
├──────────────────────────────────────┤
│  Form content                        │
├──────────────────────────────────────┤
│ [Submit ✓]  [Cancel ✕]               │  ← bottom-left: modal footer (global — automatic)
└──────────────────────────────────────┘
```

Icons and heights on ALL buttons are governed globally by `AdminPanelProvider::boot()`.
Do not set `->icon()` or `->color()` on standard action types — they are already configured.

---

## The 5 Scaffold Traits

All traits live in `App\Filament\Admin\Concerns\`.

| Trait | Use on | Provides |
|---|---|---|
| `HasEditPageScaffold` | `EditRecord` pages | Form footer icons + `standardHeaderActions()` |
| `HasCreatePageScaffold` | `CreateRecord` pages | Form footer icons |
| `HasViewPageScaffold` | `ViewRecord` pages | `standardViewHeaderActions()` |
| `HasListPageScaffold` | `ListRecords` pages | `getHeaderActions(): []` (toolbar owns Create) |
| `HasManagePageScaffold` | `ManageRecords` pages | `getHeaderActions(): []` (toolbar owns all CRUD) |

---

## Page Type Scaffolds

### EditRecord

```php
<?php

namespace App\Filament\Admin\Resources\{Module}\Pages;

use App\Filament\Admin\Concerns\HasEditPageScaffold;
use App\Filament\Admin\Resources\{Module}\{Resource}Resource;
use Filament\Resources\Pages\EditRecord;

class Edit{Resource} extends EditRecord
{
    use HasEditPageScaffold;

    protected static string $resource = {Resource}Resource::class;

    protected function getHeaderActions(): array
    {
        // Standard set: View, Delete, ForceDelete (super_admin), Restore
        return $this->standardHeaderActions();

        // To add resource-specific actions, merge them in:
        // return [...$this->standardHeaderActions(), PrintAction::make()];

        // To replace entirely (resource has no view page, no soft-delete, etc.):
        // return [DeleteAction::make()];
    }
}
```

Form footer buttons (Save Changes ✓, Cancel ✕) are injected automatically by the trait.

---

### CreateRecord

```php
<?php

namespace App\Filament\Admin\Resources\{Module}\Pages;

use App\Filament\Admin\Concerns\HasCreatePageScaffold;
use App\Filament\Admin\Resources\{Module}\{Resource}Resource;
use Filament\Resources\Pages\CreateRecord;

class Create{Resource} extends CreateRecord
{
    use HasCreatePageScaffold;

    protected static string $resource = {Resource}Resource::class;

    // No getHeaderActions() — Create pages have no header actions.
    // The Add button lives in the table toolbar on the List page.
}
```

Form footer buttons (Create +, Cancel ✕) are injected automatically by the trait.

---

### ViewRecord

```php
<?php

namespace App\Filament\Admin\Resources\{Module}\Pages;

use App\Filament\Admin\Concerns\HasViewPageScaffold;
use App\Filament\Admin\Resources\{Module}\{Resource}Resource;
use Filament\Resources\Pages\ViewRecord;

class View{Resource} extends ViewRecord
{
    use HasViewPageScaffold;

    protected static string $resource = {Resource}Resource::class;

    protected function getHeaderActions(): array
    {
        // Standard set: Edit, Delete, ForceDelete (super_admin), Restore
        return $this->standardViewHeaderActions();

        // To add resource-specific actions (e.g. Print, Approve):
        // return [...$this->standardViewHeaderActions(), PrintAction::make()];

        // Fully custom (workflow resources like LeaseApplication):
        // return [ApproveAction::make(), RejectAction::make(), ...];
    }
}
```

---

### ListRecords

```php
<?php

namespace App\Filament\Admin\Resources\{Module}\Pages;

use App\Filament\Admin\Concerns\HasListPageScaffold;
use App\Filament\Admin\Resources\{Module}\{Resource}Resource;
use App\Support\HasIconPageHeading;
use Filament\Resources\Pages\ListRecords;

class List{Resource}s extends ListRecords
{
    use HasIconPageHeading;
    use HasListPageScaffold;

    protected static string $resource = {Resource}Resource::class;

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return $this->headingWithIcon('{Resource Label}', 'heroicon-o-{icon}');
    }

    // No getHeaderActions() — the trait returns [] by default.
    // The Add/Create button lives in the table's toolbarActions().
    // Override ONLY for non-Create page-level actions (e.g. Export).
}
```

The resource's `table()` method owns the Create button:

```php
->toolbarActions([
    CreateAction::make()->label('Add {Thing}'),
    BulkActionGroup::make([DeleteBulkAction::make()]),
])
```

---

### ManageRecords

```php
<?php

namespace App\Filament\Admin\Resources\{Module}\Pages;

use App\Filament\Admin\Concerns\HasManagePageScaffold;
use App\Filament\Admin\Resources\{Module}\{Resource}Resource;
use App\Support\HasIconPageHeading;
use Filament\Resources\Pages\ManageRecords;

class Manage{Resource}s extends ManageRecords
{
    use HasIconPageHeading;
    use HasManagePageScaffold;

    protected static string $resource = {Resource}Resource::class;

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return $this->headingWithIcon('{Resource Label}', 'heroicon-o-{icon}');
    }

    // No getHeaderActions() — all CRUD is modal-driven from the table toolbar.
}
```

---

## Icon Mapping (global — do not override per-page)

These are set in `AdminPanelProvider::boot()` via `configureUsing()`:

| Action | Trigger Icon | Modal Submit Icon |
|---|---|---|
| `CreateAction` | `OutlinedPlus` + gray | `OutlinedPlus` |
| `EditAction` | `OutlinedPencilSquare` + gray | `OutlinedCheckCircle` |
| `ViewAction` | `OutlinedEye` + gray | — |
| `DeleteAction` | `OutlinedTrash` + danger | `OutlinedTrash` |
| `ForceDeleteAction` | `OutlinedTrash` + danger | `OutlinedTrash` |
| `RestoreAction` | `OutlinedArrowPath` + warning | `OutlinedArrowPath` |
| `ReplicateAction` | `OutlinedDocumentDuplicate` + gray | `OutlinedDocumentDuplicate` |
| `AssociateAction` | `OutlinedLink` + gray | `OutlinedLink` |
| `AttachAction` | `OutlinedPaperClip` + gray | `OutlinedPaperClip` |
| `DetachAction` | `OutlinedMinusCircle` + warning | `OutlinedMinusCircle` |
| Custom `Action::make()` | set per-action | `OutlinedCheckCircle` (fallback) |

All modal Cancel buttons get `OutlinedXMark` automatically.

---

## Pagination Convention

All tables use a sandwich layout: **[PREVIOUS]** page numbers **[NEXT]**, per-page select pushed far right.

This is controlled entirely by CSS in `AdminPanelProvider`. Do not set pagination options per-table unless the table has a special per-page requirement.

- PREVIOUS and NEXT are text buttons (from Filament's simple pagination template)
- Page numbers are `fi-pagination-item-btn` buttons, styled to 36px height, JetBrains Mono 11px
- The icon-only `<` and `>` chevrons inside `fi-pagination-items` are hidden — the text buttons handle navigation
- Active page gets ink fill (`#0a1512` background, parchment text)
- Per-page select is pushed to the far right via `margin-left: auto`

```
[PREVIOUS]  [1]  [2]  [3]  [NEXT]          [10 per page ▾]
```

---

## CSS Override Reference

Global button styles live in `AdminPanelProvider` inside the `renderHook` CSS block.

| Selector | What it controls |
|---|---|
| `.fi-btn` | All buttons: height 36px, inline-flex, monospace 11px, uppercase |
| `.fi-btn svg` | All button icons: 14px |
| `.fi-btn.fi-color-primary` | Ink (dark) filled buttons |
| `.fi-btn.fi-color-danger` | Danger (terracotta) filled buttons |
| `.fi-btn:not(.fi-color-*)` | Ghost style (gray border, muted text) |
| `.fi-modal-footer .fi-btn` | Modal footer buttons: min-width 80px |
| `.fi-sc-actions .fi-btn` | Form footer buttons: min-width 80px |
| `.fi-simple-main .fi-btn.fi-color-primary` | Login page submit: 44px, full-width |
| `.fi-pagination` | Flex row, sandwich layout |
| `.fi-pagination-item-btn` | Page number buttons: 36px height, JetBrains Mono |
| `.fi-pagination-item.fi-active .fi-pagination-item-btn` | Active page: terracotta fill (#c84c21) |

To override a button in a specific resource only, add an inline CSS block via
`AdminPanelProvider::renderHook` scoped to a unique class you attach to that panel section.

---

## Dashboard Widgets

The admin Dashboard (`App\Filament\Admin\Pages\Dashboard`) is a widget grid, not a
record page. Widgets live in `App\Filament\Admin\Widgets` and render inside the
standard Field-Record section chrome (parchment card + inset dashed border).

- **`StatsOverviewWidget`** (stat cards): use the **`HasContainedStatsSection`**
  trait (`App\Filament\Admin\Concerns`). Filament pins the stats section to
  `->contained(false)`, which strips the section's inner padding and makes the
  cards bleed to the dashed edge with a mis-inset heading. The trait overrides
  `getSectionContentComponent()` to restore the standard contained padding.
- **Stat count fills the grid:** `getColumns()` resolves to 3 columns unless the
  stat count `% 3 === 1`. Give a section a count that fills its rows (e.g. 3 or 6)
  so there's no ragged trailing empty cell.
- **`ChartWidget`** (pie/doughnut/bar): extend `Filament\Widgets\ChartWidget` and
  implement `getType()` — already renders in a contained section, no trait needed.
- Use the brand palette from `docs/design_system.md` for chart colours.
- Run `filament:clear-cached-components && filament:cache-components` after adding
  a new widget so it registers.

---

## Expanding to New Page Types

When a new page type is needed (e.g. a custom Dashboard, a Settings page):

1. Create a new trait in `App\Filament\Admin\Concerns\Has{Type}PageScaffold.php`
2. Follow the same pattern: document the zone, provide any helper methods
3. Add a row to this table and to the CLAUDE.md `Task → Files` table
4. Apply to the new page immediately so future sessions see the pattern
