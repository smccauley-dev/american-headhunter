# Admin Backend Design System — "Field Record" Theme

> Reverse-engineered from the live admin Properties editor, Photos tab
> (`/admin/properties/{id}/edit?tab=photos`). This is the canonical reference for
> building consistent admin pages.
>
> **Single source of truth for the theme:** the injected `<style>` block in
> [`app/Providers/Filament/AdminPanelProvider.php`](../app/Providers/Filament/AdminPanelProvider.php)
> (method `loginHeadContent()`, rendered into `<head>` via the `HEAD_END` render
> hook). There is **no** compiled theme CSS file — every rule below lives in that
> block as `!important` overrides on Filament's `fi-*` classes. The Photos tab UI
> itself is assembled in
> [`PropertyFormV2.php`](../app/Filament/Admin/Resources/Properties/Schemas/PropertyFormV2.php),
> [`EditPropertyV2.php`](../app/Filament/Admin/Resources/Properties/Pages/EditPropertyV2.php),
> and the partial
> [`resources/views/filament/admin/properties/photo-grid.blade.php`](../resources/views/filament/admin/properties/photo-grid.blade.php).

The aesthetic is a **field journal / surveyor's record**: warm parchment paper,
dark "ink" forest green, a single terracotta accent, hard offset drop-shadows
(no blur), square corners everywhere, dashed inset borders evoking a stamped
form, and an all-monospace label system in small-caps.

---

## 1. UI Framework & Libraries

| Concern | Technology |
|---|---|
| Admin framework | **Filament v4** (PHP admin panel builder) |
| Reactivity / server state | **Livewire 3** (server-driven components; `wire:click`, `mountAction`) |
| Client interactivity | **Alpine.js** (bundled by Filament — dropdowns, modals, toggles) |
| Templating | **Blade** (`.blade.php`) — not React. (React/Inertia is the *public + member* side only) |
| Styling base | **Tailwind CSS** (Filament's utility layer, under the hood) + the custom `<style>` override block |
| Icon set | **Heroicons** (outline) via Filament's `Heroicon` enum + `heroicon-o-*` string names |
| File uploads | **FilePond** (wrapped by Filament's `FileUpload` component) |
| Build tool | **Vite** (`vite build`) — see project root `package.json` |
| Fonts | **Google Fonts** (`<link>` preconnect + CSS2 import in `loginHeadContent()`) |

> **Note on the member/public side:** the member portal (`/member/...`) and public
> pages use **React 19 + Inertia.js + TypeScript**, a different (but visually
> parallel) component kit (`resources/js/Components/Member/PropertyChrome.tsx`).
> Both the admin and the member mirror use **JetBrains Mono** — the brand-standard
> mono (see `docs/design_system.md`). This document covers the **admin**
> (Filament/Blade) side only.

### Filament panel configuration (`panel()` in AdminPanelProvider)

```php
->colors([
    'primary' => Color::hex('#0a1512'),  // ink forest green
    'danger'  => Color::hex('#c84c21'),  // terracotta
    'warning' => Color::hex('#b8934a'),  // brass
    'success' => Color::hex('#6b7856'),  // sage
])
->brandName('American Headhunter')
->darkMode(false)                        // dark mode is disabled
->navigationGroups([
    'Marketplace', 'Users & Access', 'Pricing & Promotions',
    'Communications', 'Safety & Compliance', 'System',
])
```

Global **action icon defaults** are registered in `boot()` via `configureUsing()`
(every `CreateAction` gets a plus icon + gray color, `DeleteAction` a trash icon +
danger color, modal submit/cancel get check/x icons, etc.). This is why every
modal across the panel has consistent check ✓ / x ✗ footer icons.

---

## 2. Color Palette

### 2.1 Core brand colors

| Token | Hex | RGB | Role |
|---|---|---|---|
| **Ink** (forest green) | `#0a1512` | `10, 21, 18` | Primary. Text, borders, shadows, sidebar bg, primary buttons |
| **Terracotta** (accent) | `#c84c21` | `200, 76, 33` | Danger + the single accent: active tabs, active sidebar item, icons, required marks, active pagination |
| **Brass** | `#b8934a` | `184, 147, 74` | Warning. Nav group labels, brand sub-text |
| **Sage** | `#6b7856` | `107, 120, 86` | Success. Toggles (on state), description body text, brand sub on login |

### 2.2 Parchment / neutral scale (the "paper")

| Token | Hex | Role |
|---|---|---|
| Paper (card) | `#f4ecdc` | Section/card/modal background |
| Paper (page) | `#e8dcc4` | Main content area, topbar, page header |
| Paper (input/raised) | `#faf7f2` | Input fields, login card, file dropzone, repeater items |
| Tan (dashed rules) | `#a89874` | Inset dashed borders, dividers, section header underline |
| Tan (input border) | `#c9b896` | Input/select borders (1px solid) |
| Stone (card border, photo card) | `#e5e0d8` | Photo-card border, tag pill border (blade partial) |
| Cream (button text on ink) | `#e8dcc4` | Text on primary/ink buttons |
| Off-white (ghost button) | `#fafafa` | Ghost button background |

### 2.3 Functional text colors

| Use | Value |
|---|---|
| Body / strong text | `#0a1512` |
| Label text (mono, muted ink) | `rgba(10, 21, 18, 0.7)` |
| Helper / hint text | `rgba(10, 21, 18, 0.4)` |
| Placeholder text | `rgba(10, 21, 18, 0.3)` |
| Ghost button text | `rgba(10, 21, 18, 0.65)` |
| Inactive tab | `rgba(10, 21, 18, 0.45)` |
| Photo-card caption (blade) | `#374151` (gray-700) |
| Photo-card "No caption" (blade) | `#9ca3af` italic |
| Photo-card tag text (blade) | `#6b5d40` |
| "No location" muted (blade) | `#c4bdac` |
| Delete button text (blade) | `#b91c1c`, border `#fca5a5` |

> The photo-grid **blade partial** predates full theme alignment and uses a few
> raw Tailwind-gray hexes (`#374151`, `#9ca3af`, `#e5e7eb`) instead of the
> parchment palette. The React member mirror corrects these to the parchment
> tokens. Treat the parchment tokens above as canonical for new work.

### 2.4 State colors (hover / active / disabled)

| Element | Default | Hover | Active / Selected | Disabled |
|---|---|---|---|---|
| Primary button | bg `#0a1512` / text `#e8dcc4` | bg `#1c302a` | — | login: opacity 1, stays ink |
| Danger button | bg `#c84c21` / text `#f4ecdc` | bg `#8a3216` | — | — |
| Ghost button | bg `#fafafa` / text `rgba(10,21,18,.65)` / border `rgba(10,21,18,.2)` | bg `rgba(10,21,18,.06)` / text `#0a1512` | — | photo move btn: `opacity:.35; pointer-events:none` |
| Sidebar item | text `#f4ecdc` / icon `#c84c21` | bg `rgba(244,236,220,.07)` | bg `rgba(244,236,220,.08)` + 2px left border `#c84c21` | — |
| Tab | text `rgba(10,21,18,.45)` | text `rgba(10,21,18,.7)` | text `#0a1512` + 2px bottom border `#c84c21` | — |
| Icon button | text `rgba(10,21,18,.45)` | bg `rgba(10,21,18,.07)` / text `#0a1512` | — | — |
| Input | border `#c9b896` / bg `#faf7f2` | — | focus-within: border `#a89874` (no ring) | — |
| Pagination page | text `rgba(10,21,18,.55)` | bg `rgba(10,21,18,.06)` | bg `#c84c21` / text `#f4ecdc` | — |
| Login submit | bg `#0a1512` | bg `#c84c21` | — | bg stays `#0a1512` |

### 2.5 Color swatch reference

```
INK        #0a1512  ███  primary · text · borders · shadow · sidebar
TERRACOTTA #c84c21  ███  accent · danger · active states
BRASS      #b8934a  ███  warning · nav-group labels
SAGE       #6b7856  ███  success · toggles · description body
PAPER-CARD #f4ecdc  ███  sections · cards · modals
PAPER-PAGE #e8dcc4  ███  main area · topbar · header
PAPER-IN   #faf7f2  ███  inputs · login card · dropzone
TAN-DASH   #a89874  ███  dashed inset borders · dividers
TAN-BORDER #c9b896  ███  input borders
CREAM      #e8dcc4  ███  button text on ink
```

---

## 3. Typography System

### 3.1 Font families (loaded via Google Fonts)

```html
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,500;0,9..144,600;1,9..144,500&family=JetBrains+Mono:wght@300;400;500;600&family=Crimson+Pro:ital,wght@0,300;0,400;1,300;1,400&display=swap" rel="stylesheet">
```

| Family | Stack | Role |
|---|---|---|
| **JetBrains Mono** | `'JetBrains Mono', Menlo, monospace` | All UI chrome: labels, buttons, tabs, breadcrumbs, nav groups, badges, helper text, pagination |
| **Fraunces** (serif display) | `'Fraunces', Georgia, serif` | Display headings: page header, section card title (`fi-header-heading`), modal heading, brand name, AH mark |
| **Crimson Pro** (serif body) | `'Crimson Pro', Georgia, serif` | Long-form body / description text (`.ah-description-entry`) |

Weights loaded: Fraunces 500 + 600 (+ italic 500); JetBrains Mono 300/400/500/600;
Crimson Pro 300 + 400 (+ italics).

### 3.2 Type scale & usage

| Element | Font | Size | Weight | Letter-spacing | Transform | Color |
|---|---|---|---|---|---|---|
| Page header (`fi-header-heading`) | Fraunces | (Filament default ~1.875rem) | 500 | — | — | `#0a1512` |
| Section card title (`fi-section-header-heading`) | JetBrains Mono | 13px | 400 | 0.15em | uppercase | `rgba(10,21,18,.7)` |
| Section description | JetBrains Mono | 9px | — | 0.08em | — | `rgba(10,21,18,.4)` |
| Field label (`fi-fo-field-label-content`) | JetBrains Mono | 13px | 400 | 0.12em | uppercase | `rgba(10,21,18,.7)` |
| **Modal** field label | JetBrains Mono | 10px | — | 0.12em | uppercase | `rgba(10,21,18,.5)` |
| Helper / hint text | JetBrains Mono | 10px | — | 0.05em | — | `rgba(10,21,18,.4)` |
| Field error message | JetBrains Mono | 10px | — | — | — | (danger) |
| Input / textarea text | (system) | 14px | — | — | — | `#0a1512` |
| Button label (`fi-btn`) | JetBrains Mono | 11px | — | 0.12em | uppercase | per-variant |
| Tab item | JetBrains Mono | 10px | — | 0.12em | uppercase | per-state |
| Breadcrumb | JetBrains Mono | 10px | — | 0.1em | uppercase | `rgba(10,21,18,.5)` |
| Nav group label | JetBrains Mono | 10px | 400 | 0.18em | uppercase | `#b8934a` |
| Sidebar item | (Filament default) | — | — | — | — | `#f4ecdc` |
| Pagination number | JetBrains Mono | 11px | — | 0.12em | — | `rgba(10,21,18,.55)` |
| Modal heading | Fraunces | (Filament default) | 500 | — | — | `#0a1512` |
| Description body (`.ah-description-entry`) | Crimson Pro | 16px | 300 | — | line-height 1.65 | `#6b7856` |
| Brand name | Fraunces | 22px | 500 | -0.02em | — | per-context |
| Brand sub | JetBrains Mono | 9px | — | 0.22em | uppercase | muted |
| AH mark letters | Fraunces | 20px | 600 | -0.02em | — | per-context |

**Photo-grid blade (card) typography** (inline styles, monospace = browser default mono):

| Element | Size | Family | Notes |
|---|---|---|---|
| Caption | 13px | system | `#374151`; empty → `#9ca3af` italic |
| ★ Primary badge | 10px | monospace | 700 weight, 0.08em, uppercase |
| Index badge `NN / NN` | 10px | monospace | — |
| Tag pill | 10px | monospace | 0.04em, uppercase |
| Location link | 11px | monospace | `#6b7280` |
| Card buttons | 12px | system | 500 weight |

---

## 4. Spacing & Grid System

There is no formal named spacing scale (xs/sm/...) in the theme; it inherits
Filament's Tailwind `0.25rem`-based scale and applies a handful of explicit
values. The de-facto unit is **8px** (the dashed inset, shadow offset, card gaps,
badge insets all key off 8).

| Constant | Value | Where |
|---|---|---|
| Hard-shadow offset | `8px 8px 0` | All cards, tables, modals, dropdowns |
| Dashed-border inset | `8px` (top/left/right/bottom) | `.fi-section::before`, `.fi-ta-ctn::before` |
| Section header vertical padding | `1.25rem` (20px) block | `.fi-section-header` |
| Section header divider inset | `calc(100% - 48px)` | underline gradient → implies 24px section padding-inline |
| Page header padding | `24px` top / `19px` bottom | `.fi-header` |
| Button height | `36px` | `.fi-btn` (login submit is `44px`) |
| Button padding-inline | `0.875rem` (14px) | `.fi-btn` |
| Button gap (icon↔label) | `0.5rem` | `.fi-btn` |
| Pagination button height | `36px`, min-width `32px` | `.fi-pagination-item-btn` |
| Modal footer padding-top | `0.625rem` (10px) | `.fi-modal-footer` |
| Action row gap | `0.5rem` | `.fi-sc-actions .fi-ac` |
| Min button width (modal/form) | `80px` | footer + schema action buttons |

**Photo gallery grid (blade):**

```css
display: grid;
grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
gap: 16px;
```

**Photo card internals:** image padding `0`, body `padding: 10px 12px`, internal
`gap: 8px`, button row `gap: 6px` with `margin-top: auto` (pins controls to card
bottom). Tag row `gap: 4px`.

---

## 5. HTML / DOM Structure

### 5.1 Page layout (Filament panel shell)

```
body.fi-body                              (parchment #f4ecdc background)
├── .fi-topbar-ctn / .fi-topbar           (parchment #e8dcc4, 1px tan bottom border)
│   ├── .fi-topbar-start  → .ah-admin-logo (ink AH mark on parchment)
│   └── .fi-global-search-field            (square parchment input)
├── .fi-sidebar                            (DARK ink #0a1512, 2px brass-tinted right border)
│   ├── .fi-sidebar-header → .ah-admin-logo (parchment AH mark on dark)
│   └── .fi-sidebar-nav
│       └── .fi-sidebar-group
│           ├── .fi-sidebar-group-label    (brass mono small-caps)
│           └── .fi-sidebar-item[.fi-active]
│               └── .fi-sidebar-item-btn   (cream text, terracotta icon)
└── .fi-main / .fi-main-ctn                (parchment #e8dcc4)
    ├── .fi-header                         (page title Fraunces + breadcrumbs)
    └── .fi-page-content (grid)
        └── form schema → Tabs → Tab panels
```

There is **no footer** on admin content pages (the coordinates footer
`.ah-login-footer` only renders on the simple/login layout).

### 5.2 Edit Property page → Tabs

The property editor is a Filament `Tabs` schema. Tabs (from `PropertyFormV2`):
**Details · Species · Rules · Amenities · Photos · Map · Listings** (+ managers,
check-in, etc.). The Photos & Map tabs are `->visible(fn ($record) => $record !== null)`
— they only appear once the property exists (you must save before managing media).

URL tab state is encoded as `?tab=photos::data::tab` (Filament's tab query key).

```
.fi-sc-tabs                       (tabs container — bg transparent, no box)
└── nav.fi-tabs                   (1px tan bottom border, no radius)
    └── .fi-tabs-item[.fi-active] (mono small-caps; active = ink + 2px terracotta underline)
```

### 5.3 Photos tab structure

```
Tab "Photos"
└── Section "Photo Gallery"                         (fi-section — parchment card, dashed inset, hard shadow)
    │   description: "Photos shown on the public listing. The primary
    │                 photo is the cover image; use the arrows to set display order."
    ├── headerActions: [ uploadPhotosAction ]       (▲ "Upload Photos" ghost button, top-right of section)
    └── Placeholder "property_photos_display" (hiddenLabel)
        └── renderPhotosHtml() → view('filament.admin.properties.photo-grid')
            └── div.grid (auto-fill minmax(260px,1fr), gap 16px)
                └── per photo: div.card
                    ├── div (aspect-ratio 16/10) > img + ★Primary badge + "NN / NN" index badge
                    └── div.body
                        ├── caption (or italic "No caption")
                        ├── tag pills (if any)
                        ├── location link (Google Maps) OR "No location"
                        └── button row: ← → Edit Delete
```

Each card button fires a Livewire page action via
`wire:click="mountAction('<action>', { photoId: '...', ... })"` — `movePropertyPhoto`,
`editPropertyPhoto`, `deletePropertyPhoto`.

### 5.4 Edit Photo Details modal (form elements)

From `EditPropertyV2::editPropertyPhotoAction()` — `modalHeading('Edit Photo Details')`:

| Field | Component | Config |
|---|---|---|
| Caption / Description | `Textarea` | `rows(3)`, `maxLength(255)` |
| Tags | `TagsInput` | `suggestions(photoTagSuggestions())`, helper "Press Enter after each tag. Used for gallery filtering." |
| Latitude | `TextInput` (in `Grid::make(2)`) | `numeric()`, `minValue(-90)`, `maxValue(90)`, placeholder `30.267153`, helper "Where the photo was taken (WGS84). Auto-filled from the photo's EXIF GPS data when available." |
| Longitude | `TextInput` (in `Grid::make(2)`) | `numeric()`, `minValue(-180)`, `maxValue(180)`, placeholder `-97.743057`, helper "Negative values are West." |
| Primary (cover) photo | `Toggle` | `disabled($isPrimary)`; helper varies: already-primary → "This is the current primary photo. Set another photo as primary to change it.", else "Make this the cover photo shown on the public listing." |

Modal submit/cancel buttons inherit the global check ✓ / x ✗ icons.

### 5.5 Upload Photos modal (`uploadPhotosAction`)

`modalHeading('Upload Property Photos')`:

| Field | Component | Config |
|---|---|---|
| Photos | `FileUpload` | `->image()->multiple()->maxSize(10240)->maxFiles(20)->required()`, dropzone, helper "JPG, PNG, or WebP — max 10 MB each, up to 20 per batch." |
| Caption | `TextInput` | `maxLength(255)`, batch helper |
| Tags | `TagsInput` | suggestions, batch helper |
| Import photo metadata (EXIF) | `Toggle` | `default(true)`, long privacy helper |

The suggestion list (`photoTagSuggestions()`):
`aerial, habitat, food plot, stand, blind, trail camera, water, creek, pond,
access, road, gate, cabin, lodging, harvest, wildlife, boundary, terrain`.

---

## 6. Style Guide / Component Reference

### 6.1 Cards / Sections — the "Field Record" card

The signature component. Every `.fi-section`, table container, modal, dropdown,
and column-manager panel uses it:

```css
background-color: #f4ecdc;     /* parchment */
border: 1px solid #0a1512;     /* ink */
border-radius: 0;              /* square */
box-shadow: 8px 8px 0 #0a1512; /* hard offset, NO blur */
position: relative;
```

Plus a **dashed inset border** via `::before`, 8px inset on all sides:

```css
.fi-section::before {
    content: '';
    position: absolute;
    top: 8px; left: 8px; right: 8px; bottom: 8px;
    border: 1px dashed #a89874;   /* tan */
    pointer-events: none;
    z-index: 0;
}
```

Section header has **no border** — instead an inset underline drawn with a
gradient so it stops 24px short of each edge (aligning to content padding):

```css
.fi-section-header {
    background-image: linear-gradient(#a89874, #a89874);
    background-position: center bottom;
    background-size: calc(100% - 48px) 1px;
    background-repeat: no-repeat;
    padding-block: 1.25rem;
}
```

### 6.2 Buttons

All buttons: `border-radius: 0`, `height: 36px`, JetBrains Mono 11px, 0.12em
tracking, uppercase, no shadow, icon SVG forced to 14×14.

```css
.fi-btn {
    border-radius: 0; box-shadow: none; height: 36px;
    padding: 0 0.875rem;
    font-family: 'JetBrains Mono', Menlo, monospace;
    font-size: 11px; letter-spacing: 0.12em; text-transform: uppercase;
    display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem;
}
```

| Variant | Background | Text | Border | Hover |
|---|---|---|---|---|
| **Primary** (`.fi-color-primary`) | `#0a1512` | `#e8dcc4` | none | bg `#1c302a` |
| **Danger** (`.fi-color-danger`) | `#c84c21` | `#f4ecdc` | none | bg `#8a3216` |
| **Ghost** (any non-colored variant) | `#fafafa` | `rgba(10,21,18,.65)` | `1px rgba(10,21,18,.2)` | bg `rgba(10,21,18,.06)`, text `#0a1512` |
| **Login submit** | `#0a1512` | `#faf7f2` | none | bg `#c84c21`; height 44px, full-width |

> The "ghost" style is matched with a `:not(.fi-color-primary):not(.fi-color-danger):not(.fi-color-success):not(.fi-color-warning)`
> selector, so View / Cancel / Upload Photos and any unnamed secondary buttons all
> get it automatically.

**Photo-card buttons (blade, NOT `.fi-btn`)** use a local style:

```css
display:inline-flex; align-items:center; gap:4px;
padding:5px 10px; border-radius:6px;        /* note: rounded, unlike fi-btn */
background:#fff; border:1px solid #e5e7eb;
font-size:12px; font-weight:500; color:#374151;
```

Delete adds `color:#b91c1c; border-color:#fca5a5;`. Disabled move arrows:
`opacity:0.35; pointer-events:none;`.

### 6.3 Inputs

```css
/* wrapper */
.fi-input-wrp {
    border-radius: 0;
    border: 1px solid #c9b896;
    background-color: #faf7f2;
    box-shadow: none;
}
.fi-input-wrp:focus-within { border-color: #a89874; box-shadow: none; }  /* no focus ring */

/* text node */
input.fi-input, textarea.fi-input {
    background: transparent; color: #0a1512; font-size: 14px;
}
input.fi-input::placeholder { color: rgba(10,21,18,.3); }
```

- **Select:** same parchment wrapper (`#faf7f2`, ink text).
- **File upload dropzone** (`.fi-fo-file-upload-input-ctn`): `border-radius:0`,
  `1px dashed #a89874` on `#faf7f2`; hover → border `#0a1512`, bg `rgba(10,21,18,.03)`.
- **Toggle:** when on → `background-color: #6b7856` (sage).
- **Repeater item:** `#faf7f2` bg, `1px solid #c9b896`, square; header has
  `1px solid #a89874` bottom border + mono 10px label.
- **Error message:** JetBrains Mono 10px.

### 6.4 Tabs

```css
nav.fi-tabs {
    background: transparent; border: none;
    border-bottom: 1px solid #a89874;     /* tan rule under the whole row */
    padding: 0; gap: 0;
}
.fi-tabs-item {
    background: transparent;
    font-family: 'JetBrains Mono', Menlo, monospace;
    font-size: 10px; letter-spacing: 0.12em; text-transform: uppercase;
    color: rgba(10,21,18,.45);
    border-bottom: 2px solid transparent;
    padding-bottom: 10px; margin-bottom: -1px;   /* overlaps the nav rule */
}
.fi-tabs-item.fi-active { color: #0a1512; border-bottom-color: #c84c21; }
.fi-tabs-item:hover     { color: rgba(10,21,18,.7); }
```

### 6.5 Badges / Tags

- **Filament badge** (`.fi-badge`): square (`border-radius: 0`).
- **Photo tag pill** (blade): `background:#f5f1eb; color:#6b5d40; border:1px solid #e5e0d8;`
  monospace 10px, 0.04em, uppercase, `padding:2px 7px; border-radius:9999px` (pill).
- **★ Primary badge:** `background:#0a1512; color:#fff; border:1px solid #a89874;`
  monospace 10px/700, 0.08em, uppercase, `padding:3px 9px; border-radius:3px`.
- **Index badge `NN / NN`:** `background:rgba(10,21,18,0.65); color:#fff;`
  monospace 10px, `padding:2px 7px; border-radius:3px`.

### 6.6 Modals

```css
.fi-modal-window {
    background: #f4ecdc; border-radius: 0;
    border: 1px solid #0a1512; box-shadow: 8px 8px 0 #0a1512;
}
.fi-modal-header  { border-bottom: 1px solid #a89874; background: transparent; }
.fi-modal-heading { font-family: 'Fraunces', Georgia, serif; font-weight: 500; color: #0a1512; }
.fi-modal-footer  { border-top: 1px solid #a89874; padding-top: 0.625rem; }
.fi-modal-footer .fi-btn { min-width: 80px; }
```

Modal labels are smaller than page labels (10px vs 13px) and lighter
(`rgba(10,21,18,.5)`).

### 6.7 Tables

Table container (`.fi-ta-ctn`) gets the full field-card treatment (parchment,
ink border, hard shadow, dashed inset, `overflow:hidden`). Toolbar/content/footer
are inset 8px to clear the dashed border. Rows are `#f4ecdc`; row hover
`rgba(10,21,18,.04)`; dividers `#a89874`. Column header row bg `rgba(10,21,18,.04)`.

**Pagination** is restyled into a `[PREVIOUS] [1][2][3] [NEXT] ...per-page`
sandwich (flex row, `gap: 0.375rem`): the overview text is hidden, icon
chevrons hidden, page buttons are 36px square mono, the active page is filled
terracotta `#c84c21` with cream text.

### 6.8 Alerts / Notifications

Notifications use Filament's default toast component (`Notification::make()->success()/->danger()`),
not custom-themed in the `<style>` block — they inherit Filament defaults but pick
up the panel's mapped `success`/`danger`/`warning` colors (sage / terracotta / brass).

---

## 7. Visual Effects

| Effect | Value |
|---|---|
| **Border radius** | `0` virtually everywhere (square). Exceptions: photo-card `8px` (card) / `6px` (buttons) / `3px` (badges) / `9999px` (tag pills) — all in the blade partial. AH mark corner brackets are square. |
| **Box shadow** | One pattern only: `8px 8px 0 #0a1512` — hard, no blur, ink. Applied to cards, tables, modals, dropdowns, column-manager. Everything else `box-shadow: none`. |
| **Focus ring** | Removed (`--tw-ring-shadow: none`); focus shown via border color shift to `#a89874`. |
| **Transitions** | Sidebar item `background-color/color 0.12s ease`; login submit `background-color 0.2s ease`. No other animation libraries. |
| **Opacity** | Disabled photo move arrows `0.35`; many muted text colors use `rgba(...,.3–.7)` instead of opacity. |
| **Dashed inset** | `1px dashed #a89874`, 8px inset — the "stamped form" motif on every card. |
| **Corner brackets** | Login card (`.fi-simple-main`) and AH mark draw L-shaped registration marks via `::before`/`::after` (14px / 8px, 1.5px ink). |

---

## 8. Responsive Design

The custom `<style>` block is **largely breakpoint-agnostic** — it contains no
media queries; responsiveness is delegated to:

1. **Filament's underlying Tailwind layout** (responsive sidebar collapse,
   stacking, the `Grid::make(2)` lat/lng pair collapsing to 1 column on narrow
   screens via Tailwind's responsive grid classes).
2. **Intrinsic CSS grid** in the photo gallery:
   `repeat(auto-fill, minmax(260px, 1fr))` reflows columns fluidly with no
   explicit breakpoints — 1 column on phones, more as width allows.

Approach is effectively **desktop-first** (admin tool), relying on Filament's
defaults for mobile. There is one viewport-height fix:
`.fi-main { height: auto }` + `.fi-layout { min-height: 100dvh; height: auto }`
to avoid a double-height scroll area (documented inline in the source).

Touch targets meet the 36px button / 44px login-submit minimums.

---

## 9. Icon System

| Aspect | Detail |
|---|---|
| Library | **Heroicons** (outline variants) |
| In PHP config | `Filament\Support\Icons\Heroicon` enum (`Heroicon::OutlinedPlus`, `OutlinedTrash`, `OutlinedPencilSquare`, `OutlinedCheckCircle`, `OutlinedXMark`, `OutlinedArrowUpTray`, etc.) |
| In component schemas | string names, e.g. `->icon('heroicon-o-arrow-up-tray')` |
| Size in buttons | forced to **14×14** (`.fi-btn svg`) |
| Size on login submit | **11×11** (spinner constrained) |
| Sidebar item icons | **terracotta `#c84c21`** (default + active + hover all stay terracotta) |
| Icon buttons | `rgba(10,21,18,.45)` → hover `#0a1512` on `rgba(10,21,18,.07)` |
| Inline SVG (location pin) | hand-written 11×11 outline pin in the blade partial (`stroke="currentColor"`, `#6b7280`) |
| Action default icons | set globally in `boot()`: Create=plus, Edit=pencil-square, Delete/ForceDelete=trash, View=eye, Restore=arrow-path, Export=arrow-down-tray, Import=arrow-up-tray, etc.; modal submit=check-circle, cancel=x-mark |

---

## 10. Accessibility & Standards

| Concern | Implementation |
|---|---|
| Image alt text | `alt="{{ $photo->caption ?? 'Property photo' }}"` in the grid |
| Lazy loading | `loading="lazy"` on gallery images |
| Link safety | external map link `target="_blank" rel="noopener"` with `title` tooltip |
| Button titling | move buttons carry `title="Move earlier"/"Move later"`; location link `title="Open in Google Maps"` |
| Form labels | Filament associates `<label>` ↔ input automatically; required marks render terracotta (`.fi-fo-field-label-required-mark`) |
| Keyboard / focus | Filament/Alpine handle focus trapping in modals & dropdowns; focus visible via border-color shift (note: the explicit ring is suppressed — a deliberate aesthetic tradeoff, watch for low-contrast focus on inputs) |
| Toggle semantics | Filament toggle exposes proper `role="switch"` / checked state |
| Color contrast | Ink-on-parchment and cream-on-ink are high contrast; the muted helper text at `rgba(10,21,18,.4)` on parchment is the weakest pairing — acceptable for hints, avoid for essential copy |

---

## 11. Developer Notes / Conventions

| Topic | Detail |
|---|---|
| **Theme location** | All theme CSS is a single inlined `<style>` in `AdminPanelProvider::loginHeadContent()`, injected via `PanelsRenderHook::HEAD_END`. To change a color/spacing, edit that block — there is **no** `resources/css/filament/*` theme file. |
| **Override mechanism** | Every rule is an `!important` override on Filament's `fi-*` BEM-ish classes. When adding new components, target the `fi-*` class, not a custom class. |
| **Custom classes** | Only `ah-*` prefixed classes are bespoke (`ah-admin-logo`, `ah-admin-mark*`, `ah-description-entry`, `ah-login-*`). |
| **Build** | Vite (`npm run build`); no TypeScript typecheck step exists in this project (esbuild strips types). |
| **Folder structure (admin)** | `app/Filament/Admin/Resources/<Model>/` → `Schemas/` (form definitions), `Pages/` (Edit/List/Create + custom actions), `Tables/`. Blade partials in `resources/views/filament/admin/...`. |
| **Action pattern** | Reusable form/table actions are `Action::make('camelName')` methods on the Page class, mounted from Blade via `wire:click="mountAction('camelName', {...})"`. |
| **Naming** | Filament actions camelCase (`editPropertyPhoto`); section/tab labels Title Case; helper texts are full sentences. |
| **Member mirror** | When changing this admin UI, the member React mirror (`resources/js/Components/Member/PropertyChrome.tsx` + `PropertyPhotosTab.tsx` / `PropertyMapTab.tsx`) is intentionally kept pixel-parallel — both use JetBrains Mono, so update both together. |
| **IDE** | No JetBrains-specific plugin config is part of the rendered page; project is editor-agnostic. |

---

## 12. Quick-Start Template (new admin card + modal)

```php
// In a *Schemas/<Model>FormV2.php tab:
Section::make('Your Card Title')                       // → mono small-caps heading
    ->description('One sentence of mono helper text.') // → 10px tan
    ->headerActions([self::someAction()])              // → ghost button, top-right
    ->schema([
        TextInput::make('field')->label('Field Name'), // → parchment square input
        Grid::make(2)->schema([ /* two columns */ ]),  // → collapses to 1 on mobile
        Toggle::make('flag')->label('Flag'),           // → sage when on
    ]);

// A modal action on the Page class:
public function someAction(): Action
{
    return Action::make('someAction')
        ->modalHeading('Modal Title')                  // → Fraunces serif heading
        ->form([ /* fields */ ])                       // → 10px mono labels
        ->action(fn (array $data) => /* ... */);       // → check ✓ / x ✗ footer (global default)
}
```

You automatically inherit: square corners, parchment card, ink border + `8px 8px 0`
shadow, dashed inset, mono labels, themed buttons, and consistent modal chrome —
**no per-component CSS required.**
```
