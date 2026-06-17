# American Headhunter — Design System & Theme Reference

Load this file before any UI work — admin or frontend. It is the single source of truth for all visual design decisions. When any value changes here, update both `resources/css/app.css` and `app/Providers/Filament/AdminPanelProvider.php`.

---

## Color Tokens

Tokens are defined in `resources/css/app.css` `:root`. The admin panel injects these as hardcoded hex values in `AdminPanelProvider.php` — there is no shared CSS file between the two systems, so both must be kept in sync manually.

### Full Palette

| Token | Hex | RGB | Role |
|---|---|---|---|
| `--ink` | `#0a1512` | 10, 21, 18 | Primary text, borders, dark backgrounds |
| `--ink-soft` | `#142420` | 20, 36, 32 | Dark form input backgrounds (e.g. login fields) |
| `--ink-lift` | `#1c302a` | 28, 48, 42 | Primary button hover; body text on light surfaces |
| `--parchment` | `#e8dcc4` | 232, 220, 196 | Page background, topbar, main content area |
| `--parch-dim` | `#c9b896` | 201, 184, 150 | Input border (rest state) |
| `--parch-deep` | `#a89874` | 168, 152, 116 | Dividers, section lines, input border (focused) |
| `--bone` | `#f4ecdc` | 244, 236, 220 | Section cards, table rows, modal backgrounds |
| `--paper` | `#faf7f2` | 250, 247, 242 | Input field backgrounds, login card — lightest surface |
| `--blaze` | `#c84c21` | 200, 76, 33 | Primary accent — CTAs, active states, icons, danger |
| `--blaze-dim` | `#8a3216` | 138, 50, 22 | Blaze hover / pressed |
| `--sage` | `#6b7856` | 107, 120, 86 | Toggle-on, description text, secondary labels |
| `--sage-dim` | `#4a5440` | 74, 84, 64 | Muted metadata, supporting body text |
| `--brass` | `#b8934a` | 184, 147, 74 | Gold accent — sidebar labels, footer links, nav strips |
| `--brass-dim` | `#7a6028` | 122, 96, 40 | Brass hover / pressed |
| `--rust` | `#722814` | 114, 40, 20 | Search submit hover, deep destructive |

### Semantic Mapping

| Purpose | Token |
|---|---|
| Page / layout background | `--parchment` |
| Raised card surface | `--bone` |
| Input field background | `--paper` |
| Input border — rest | `--parch-dim` |
| Input border — focus | `--parch-deep` |
| Horizontal dividers | `--parch-deep` |
| Primary text | `--ink` |
| Supporting / body text | `--ink-lift` |
| Muted labels | `--ink` at 45–50% opacity |
| Primary action / accent | `--blaze` |
| Action hover | `--blaze-dim` |
| Gold / navigation accent | `--brass` |
| Success / toggle active | `--sage` |
| Card hard shadow | `--ink` (solid, 8px offset, no blur) |
| Dashed inset border | `--parch-deep` |

---

## Ink Opacity Scale

Muted variants of `--ink` (`#0a1512`) use opacity rather than separate tokens.

| Opacity | Hex approx. | Use |
|---|---|---|
| 3–4% | — | Row hover tint, column header bg |
| 6–7% | — | Ghost button hover fill, collapse button hover |
| 15% | — | Ghost button border, faint separators |
| 20% | — | Standard separator / ghost border |
| 30–35% | — | Placeholder text, muted icons |
| 40–45% | — | Section description, collapse button |
| 45–50% | — | Field labels, breadcrumbs, tab items |
| 65% | — | Ghost button text label |

---

## Typography

| Role | Font | Size | Weight | Case | Tracking |
|---|---|---|---|---|---|
| Display heading | Fraunces | variable | 400–600 | — | -0.02em |
| Display italic accent | Fraunces italic | — | 400–500 | — | — |
| Body / lede text | Crimson Pro | 16–18px | 300–400 | — | — |
| UI labels / mono text | JetBrains Mono | 9–11px | 400–600 | uppercase | 0.12–0.25em |
| Coordinates / metadata | JetBrains Mono | 10px | 400 | uppercase | 0.15–0.20em |

Both systems load Fraunces, Crimson Pro, and JetBrains Mono from Google Fonts.

> **Mono standard — non-negotiable.** JetBrains Mono is the *only* monospace
> typeface permitted anywhere on the site — admin, member, public, and email.
> No other mono face (Instrument Mono, Roboto Mono, Source Code Pro, Courier,
> Consolas, SF Mono, etc.) may be introduced. The canonical stack is
> `'JetBrains Mono', Menlo, monospace` (the trailing entries are fallbacks
> only, never a primary choice). JetBrains is required because the design
> system leans on mono weight for hierarchy (400/500/600) and JetBrains ships
> the full 300–800 range; single-weight faces cannot express it. When this
> value changes, update both `resources/css/app.css` and
> `app/Providers/Filament/AdminPanelProvider.php`.

---

## Component Patterns

### Field Card

The core UI primitive. Used for property listings, admin section panels, modals, and tables.

| Property | Value |
|---|---|
| Background | `--bone` |
| Border | `1px solid --ink` |
| Box shadow | `8px 8px 0 --ink` (hard offset, no blur, no spread) |
| Border radius | `0` |
| `::before` inset | `1px dashed --parch-deep`, inset 8px on all sides |

### Buttons

| Variant | Background | Text | Border | Hover bg |
|---|---|---|---|---|
| Primary | `--ink` | `--parchment` | `--ink` | `--ink-lift` |
| Ghost / Gray | transparent | `--ink` @ 65% | `--ink` @ 20% | `--ink` @ 6% |
| Danger | `--blaze` | `--bone` | none | `--blaze-dim` |
| CTA (nav / search) | `--ink` | `--bone` | `--ink` | `--blaze` |

All buttons: `border-radius: 0` · `font-family: JetBrains Mono` · `font-size: 11px` · `letter-spacing: 0.12–0.15em` · `text-transform: uppercase`.

### Section / Field Labels (admin)

```
font-family:    JetBrains Mono
font-size:      10px
letter-spacing: 0.2em
text-transform: uppercase
color:          --ink @ 45–50% opacity
```

### Tabs (admin)

| Element | Rule |
|---|---|
| Outer container (`.fi-sc-tabs`) | transparent, no radius, no shadow, no border |
| Nav (`.fi-tabs`) | transparent; `border-bottom: 1px solid --parch-deep` |
| Tab item (`.fi-tabs-item`) | JetBrains Mono 10px uppercase; `border-bottom: 2px solid transparent` |
| Active tab | `color: --ink`; `border-bottom-color: --blaze` |

### Sidebar (admin only)

| Element | Value |
|---|---|
| Background | `--ink` |
| Right border | `1px solid --brass @ 20% opacity` |
| Group label | `--brass` · JetBrains Mono 10px uppercase |
| Nav item text | `--bone` (`#f4ecdc`) |
| Nav item icon | `--blaze` |
| Active item | bg `--bone @ 8% opacity` · `border-left: 2px solid --blaze` |

---

## Implementation Notes

### Frontend — `resources/css/app.css`
- Tokens live in `:root` as CSS custom properties
- Components reference tokens via `var(--token)`
- Built by Vite; served as a hashed CSS bundle to Inertia.js/React pages
- Applies only to public-facing routes and customer/member portals

### Admin — `app/Providers/Filament/AdminPanelProvider.php`
- CSS injected as a `<style>` block via `PanelsRenderHook::HEAD_END`
- Applies to **all** `/admin/*` routes — no per-page CSS files
- Uses hardcoded hex values; the admin does not have access to `app.css` tokens at runtime
- Filament 5: all action classes are in `Filament\Actions\` (not `Filament\Tables\Actions\`)
- `Section::description()` not `->helperText()` for subtitle text on schema sections

### Keeping Parity
1. Update the token table in this file
2. Update `resources/css/app.css` `:root`
3. Find and replace the matching hex value in `AdminPanelProvider.php`
