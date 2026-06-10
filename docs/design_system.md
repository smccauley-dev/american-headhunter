# American Headhunter — Design System

This document captures the visual identity, typography, color, spacing, and component conventions for the American Headhunter website and all applications built on it. It is the authoritative reference for any interface work on this project.

---

## Brand Identity

### Name & Domain
- **Product name:** American Headhunter
- **Domain:** americanheadhunter.com
- **Tagline:** Hunt Better · Lease Smarter

### Positioning
America's most complete hunting lease marketplace. A vertical SaaS connecting landowners with hunters, wrapping every part of that relationship — discovery, bidding, contracts, e-signatures, payments, field operations, safety, and compliance — in one platform.

### Voice & Tone
- **Confident but not loud.** The product sells trust, not hype.
- **Rooted in tradition.** Hunting is generational. The brand respects that.
- **Specific and concrete.** Talk about acres, counties, species, seasons — never vague marketing language.
- **Never corporate-SaaS.** Avoid words like "leverage," "solutions," "empower," "unlock." Write like a field journal, not a pitch deck.

**Good:** "Premium hunting land, surveyed and listed."
**Bad:** "Discover your perfect hunting experience with our innovative platform."

---

## Design Amplitude by Surface

The brand DNA is consistent across every surface of American Headhunter — typography, color palette, sharp geometry, and the editorial voice carry through from marketing page to deep admin screen. But the **amplitude of ornamental elements flexes** based on what the user is doing. A hunter checking in at 4:30am in the cold doesn't need topographic contour lines behind their gate code.

This is how real design systems scale: the brand doesn't get diluted, it gets expressed at different intensities depending on context.

### The 5 Surfaces

| Surface | User | Primary activity | Amplitude |
|---|---|---|---|
| **Public Marketing** | Unauthenticated visitor | Browsing, evaluating, deciding to trust | **Full** — every ornamental element on |
| **Customer Portal** | Prospective lessee | Applying, negotiating, comparing listings | **High** — editorial framing, tighter density |
| **Member Portal** | Active lessee | Checking in, logging, accessing credentials | **Low** — working tool, decoration steps back |
| **Admin Backend** | Staff, landowners | Managing data, moderating, operations | **Minimum** — Filament defaults with brand tokens |
| **Reports Portal** | Landowners, admins | Reading summaries, exporting PDFs | **High** — editorial framing suits printed reports |

### What stays consistent everywhere

These elements are the brand. Do not flex on them:

- Fraunces / Crimson Pro / JetBrains Mono typography
- Ink / parchment / blaze / brass / sage palette
- Sharp geometry — no rounded corners on containers
- Coordinate displays wherever location data exists
- Monospace technical labels (IDs, timestamps, dates)
- Field Record card pattern for official documents

### What flexes by surface

| Element | Public | Customer | Member | Admin | Reports |
|---|---|---|---|---|---|
| Topographic backgrounds | Full | Subtle on dashboard | Off | Off | Accent only |
| Registration marks | Full | Optional, on major pages | Off | Off | On report headers |
| Chapter numbering | Full | Selective (apply flow) | Off | Off | Full |
| Solid offset shadows | 8px | 6px | 4px or none | Default Filament | 6px |
| Compass rose | Hero only | Off | Off | Off | Optional on covers |
| Decorative dividers | Full | Selective | Minimal | Off | Full |
| Hero-scale headlines (80px+) | Yes | Dashboard only | No | No | Report covers |
| Animation / staggered reveals | Yes | Page loads only | Off | Off | Off |

### Surface-specific guidance

**Public Marketing (`/`, `/properties`, `/auctions`, `/about`)**
Full editorial treatment. Every signature element on. The visitor is deciding whether to trust this brand with the most valuable lease they'll ever sign — atmosphere earns that trust. This is the only surface where we can spend pixels on mood.

**Customer Portal (`/apply`, application flows, property comparisons)**
Inherits the marketing treatment but denser. Keep chapter-numbered section headings on major application steps ("Chapter I — Submit Your Application", "Chapter II — Review Terms"). Field Record cards are ideal for summarizing proposed lease terms side-by-side with the hunter's application. Topographic backgrounds appear on the main portal dashboard only, not on every screen.

**Member Portal (`/member`, check-in, harvest logging, gate codes)**
Working tool mode. Preserve typography and palette, drop the ornament. Dashboard sections use clean parchment backgrounds with thin parch-deep dividers rather than topo lines. Field Record cards still work beautifully for "here is your active lease," "here is your next hunt booking," "here is your digital ID card." Coordinate displays stay because they're functional, not decorative. Member portal should feel like a well-designed field journal that the hunter actually uses — not a marketing brochure.

**Admin Backend (`/admin`, Filament resources)**
Filament is a mature admin framework with strong UI conventions. Fighting Filament's defaults costs time and creates bugs. Instead:
- Override Filament's Tailwind color tokens to match our palette (ink primary, blaze accent, brass secondary)
- Set Fraunces as the heading font for Filament resources
- Keep JetBrains Mono for table columns containing IDs, timestamps, coordinates
- Accept that admin tables, filter panels, and form fields follow Filament patterns
- Do NOT force topographic backgrounds, registration marks, or chapter numbering into admin screens

The admin's job is to manage data efficiently. A landowner reviewing 40 applications needs a clean table, not an editorial layout.

**Reports Portal (`/reports`, annual recaps, harvest summaries, financial statements)**
The editorial treatment snaps right back on. Chapter numbering actually helps readers navigate multi-section reports ("Chapter I — Financial Summary", "Chapter II — Harvest Data", "Chapter III — Lease Activity"). Registration marks frame printable pages beautifully. When exported to PDF, these look like real bound reports — exactly the feel we want for a product selling trust.

---

## Design Direction: Topographic Editorial

The aesthetic crosses a heritage hunting publication with a surveyor's field journal.

**Why this direction?** Every other hunting-tech platform looks like a generic real-estate listing site with a forest green accent color. American Headhunter's platform touches land records, legal deeds, acreage, GPS coordinates, and county-level geography. The visual language should come *from* that world — maps, cartography, field journals, deeds — not bolted on top of generic SaaS patterns.

**Signature elements:**
- Topographic contour lines as an atmospheric texture throughout
- GPS coordinates displayed as decorative data (not just on maps)
- Registration marks in page corners like a field surveyor's form
- Chapter-based section numbering (Chapter I, Chapter II, Chapter III)
- Field Record cards styled like surveyor forms, with offset drop shadows and rotated verification stamps
- Custom compass rose element in the hero

---

## Typography

Three typefaces. Each has a distinct role. Do not substitute.

### Display — Fraunces
```css
font-family: 'Fraunces', Georgia, serif;
```

An optical serif with exceptional italics and a variable optical-size axis. Used for headlines, property names, section titles, and statistics. Use the italic style for accent words (not entire phrases) — italicizing an entire heading loses impact.

**Weights in use:** 300 (light), 400 (regular), 500 (medium), 600 (semibold)
**Optical size:** Set `font-variation-settings: "opsz" 144` for display sizes above 48px — this unlocks the display-optimized cut with tighter tracking and more pronounced contrast.

### Body — Crimson Pro
```css
font-family: 'Crimson Pro', Georgia, serif;
```

A refined book serif. Used for paragraph text, body copy, descriptions, and italic asides. Works especially well for the italic "lede" paragraphs that accompany chapter headings.

**Weights in use:** 300 (light — preferred for body), 400 (regular), 500 (medium)

### Mono — JetBrains Mono
```css
font-family: 'JetBrains Mono', Menlo, monospace;
```

Used for technical data: coordinates, property IDs, record numbers, section labels, button text, timestamps, dates, and footnotes. The monospace weight adds a cartographic/survey feeling to the overall composition.

**Weights in use:** 400 (regular), 500 (medium), 600 (semibold)
**Letter spacing:** Always tracked out — minimum `letter-spacing: 0.1em`, typically 0.15–0.25em for labels.

### Type Scale

| Use | Size | Font | Weight | Style |
|---|---|---|---|---|
| Hero headline | clamp(54px, 8vw, 124px) | Fraunces | 400 | Regular; italic on accent word |
| Chapter heading | clamp(40px, 5vw, 68px) | Fraunces | 400 | Regular; italic on accent word |
| CTA heading | clamp(52px, 7vw, 104px) | Fraunces | 400 | Italic on accent word |
| Card/property name | 24px | Fraunces | 500 | Regular |
| Field title | 26px | Fraunces | 500 | Italic on accent word |
| Testimonial text | clamp(24px, 3.2vw, 42px) | Fraunces | 400 | Italic |
| Stat numbers | clamp(48px, 5vw, 80px) | Fraunces | 500 | Regular |
| Chapter lede | 18px | Crimson Pro | 300 | Regular |
| Body paragraph | 16–17px | Crimson Pro | 300 | Regular |
| Italic asides | 15px | Crimson Pro | 300 | Italic |
| Button text | 11px | JetBrains Mono | 600 | Uppercase, 0.15em tracking |
| Section label | 11px | JetBrains Mono | 500 | Uppercase, 0.15em tracking |
| Data label | 10px | JetBrains Mono | 500 | Uppercase, 0.2em tracking |
| Coordinate display | 10–11px | JetBrains Mono | 400 | 0.08–0.15em tracking |

---

## Color Palette

### Primary — Ink & Parchment
The foundation. Every page should feel like it was printed on good paper.

```css
--ink:        #0a1512;   /* Near-black with green undertone */
--ink-soft:   #142420;   /* Slightly lifted ink */
--ink-lift:   #1c302a;   /* Secondary ink for body copy */

--parchment:  #e8dcc4;   /* Primary page background */
--parch-dim:  #c9b896;   /* Secondary surfaces, muted backgrounds */
--parch-deep: #a89874;   /* Borders, dividers, registration marks */
--bone:       #f4ecdc;   /* Elevated cards, lightest surface */
```

### Accent — Blaze Orange
The sharp accent. Used sparingly for emphasis, CTAs, and italicized accent words in headings. Never use as a large-area fill.

```css
--blaze:      #c84c21;   /* Primary accent */
--blaze-dim:  #8a3216;   /* Darker blaze for hover states */
--rust:       #722814;   /* Deepest blaze, used in dark mode */
```

### Secondary — Brass
Warmth. Used for secondary accents on dark backgrounds, footer links, species glyphs.

```css
--brass:      #b8934a;   /* Primary brass */
--brass-dim:  #7a6028;   /* Subdued brass — borders on dark */
```

### Tertiary — Sage
Quiet earth tone. Used for body labels, dim metadata, and anywhere that needs color without shouting.

```css
--sage:       #6b7856;
--sage-dim:   #4a5440;
```

### Color Usage Rules

| Element | Color |
|---|---|
| Primary page background | `--parchment` |
| Dark section background | `--ink` |
| Primary text on light | `--ink` |
| Body text on light | `--ink-lift` |
| Text on dark | `--bone` or `--parch-dim` |
| Borders / dividers on light | `--parch-deep` |
| Borders / dividers on dark | `--brass-dim` |
| CTAs (primary button) | `--ink` background, `--bone` text |
| CTAs on hover | `--blaze` background |
| Accent words in headings | `--blaze` |
| Technical labels | `--blaze` or `--brass` (depending on theme) |
| Coordinates / monospace | `--sage-dim` or `--brass` |

**The 60-30-10 rule applied:**
- 60% parchment / ink (foundation)
- 30% secondary — brass, sage, parch-dim (texture and depth)
- 10% blaze orange (accent only)

---

## Spatial System

### Container
- Max content width: **1400px**
- Side padding: 40px (desktop), 24px (tablet), 20px (mobile)

### Section Padding
- Chapter sections: **120px** vertical
- CTA sections: **140px** vertical
- Hero: **140px top, 80px bottom**
- Search bar: **20px internal**
- Stats strip: **72px vertical**

### Grid Gaps
- Property grid: **32px**
- Chapter header (two-column): **80px**
- Footer top section: **56px**

### Hover Lift
Cards lift with translate + shadow:
```css
transform: translate(-4px, -4px);
box-shadow: 8px 8px 0 var(--ink);
```
The shadow is solid black (not soft/blurred) — this reinforces the printed-paper feel.

---

## Signature Elements

### Topographic Contour Background
The primary atmospheric texture. Embedded as inline SVG data URI, tiled at 800×800px.

```css
.topo-bg {
  background-image: url("data:image/svg+xml,...contour paths...");
  background-size: 800px 800px;
}
```

Two variants:
- `.topo-bg` — dark lines on light parchment (for light sections)
- `.topo-bg-dark` — brass lines on ink (for dark sections)

Apply as an absolutely-positioned layer inside hero, species, testimonial, and footer sections.

### Registration Marks
Corner marks at each section boundary — same visual language as a surveyor's form or printer's registration. 24×24px L-shapes in the four corners.

```html
<div class="reg-mark reg-tl"></div>
<div class="reg-mark reg-tr"></div>
<div class="reg-mark reg-bl"></div>
<div class="reg-mark reg-br"></div>
```

### Chapter Numbering
Every major section is numbered like a book chapter:
- Chapter I — The Atlas (featured properties)
- Chapter II — The Almanac (species browser)
- Chapter III — The Expedition (how it works)

New sections should continue the numbering. The label format is:
```
[Monospace 11px, blaze color] CHAPTER [ROMAN] — [SECTION NAME]
```
With a 24px blaze line prefix.

### Field Record Cards
The signature card pattern. Used for hero feature cards and any "official-looking" data presentation.

**Anatomy:**
- 1px solid ink border
- 8px offset solid ink drop shadow (no blur)
- Inner dashed parchment border at 8px inset
- Header with section label + ID number + rotated "verified" stamp
- Dotted-line divided data rows
- Footer with price + CTA link

### Compass Rose
Animated SVG compass in the hero, rotating at 120s per full rotation. Intentionally slow — it should feel alive but not distracting.

### Coordinate Display
GPS coordinates appear throughout:
- Nav strip: `N 32°14'27" W 97°38'52"`
- Property cards (top-right overlay): `30.88° N · 100.47° W`
- Field Record rows: `29.31° N · 100.42° W`
- Footer meta: `Est. 2025 · N 32°14'27" · W 97°38'52"`

Format consistently: decimal degrees with direction, separated by middle-dot.

### Verification Stamp
Rotated 6 degrees counter-clockwise, 1.5px blaze border, blaze text:
```css
transform: rotate(-6deg);
border: 1.5px solid var(--blaze);
color: var(--blaze);
```

---

## Component Library

### Buttons

**Primary (solid)**
```css
background: var(--ink);
color: var(--bone);
padding: 16px 32px;
font: 600 11px / JetBrains Mono;
letter-spacing: 0.15em;
text-transform: uppercase;
/* Hover: background becomes blaze */
```

**Outline**
```css
background: transparent;
color: var(--ink);
border: 1px solid var(--ink);
/* Hover: inverts to solid */
```

**CTA link (no button)**
```css
color: var(--blaze);
border-bottom: 1px solid currentColor;
padding-bottom: 4px;
/* Mono, uppercase, 11px, 0.18em tracking */
```

### Property Card

```
┌─────────────────────────────────┐
│  [TAG]              [COORD]      │  ← Image area with overlays
│   Image gradient                 │
│   Silhouette horizon line        │
├─────────────────────────────────┤
│  COUNTY · STATE                  │  ← Location (mono, small)
│  Property Name                   │  ← Display serif, 24px
│  ─────────────────────           │
│  2,840 | 18     | Season         │  ← Specs (divided)
│  acres | stands | lease          │
│  ─────────────────────           │
│  [Whitetail] [Turkey] [Hog]      │  ← Species pills
│  ─────────────────────           │
│  $14,500 / season       View →  │
└─────────────────────────────────┘
```

### Search Bar

Horizontal five-column layout on ink background:
- 4 dropdowns with blaze labels and bone values
- 1 blaze CTA button at the end
- Separator: 1px rgba(184,147,74,0.2) verticals between fields

### Section Heading Pair

Every chapter uses this two-column pairing:
- Left: section number + chapter heading (1fr)
- Right: chapter lede paragraph (1fr)
- Gap: 80px
- The lede has a left border (1px parch-deep) and 24px left padding

---

## Animation Principles

Motion should feel observed, not animated. Subtle, slow, intentional.

### On page load
Staggered fade-up reveals at ~200ms intervals. Hero headline lines animate in sequence (0.3s → 0.5s → 0.7s). Use CSS `@keyframes` — no library dependencies for the public site.

```css
@keyframes fadeUp {
  from { opacity: 0; transform: translateY(24px); }
  to   { opacity: 1; transform: translateY(0); }
}
```

### Hover states
- Property cards: 300ms translate + shadow
- Species cards: 400ms background + 400ms glyph rotation/scale
- Buttons: 250ms color transition
- Links: 300ms underline scaleX from left origin

### Scroll-triggered
Navigation compacts at 40px scroll. Background opacity and padding shift.

### Don't use
- Parallax
- Heavy scroll-jack animations
- Intersection observer reveals on every single element
- Smooth-scroll libraries (`scroll-behavior: smooth` is enough)

---

## Responsive Breakpoints

| Breakpoint | Behavior |
|---|---|
| 1400px+ (desktop) | Full grid, 1400px max container |
| 1100px (tablet) | Nav links hide, hero becomes single column, grids collapse to 2-up |
| 700px (mobile) | Single-column everything, nav strip hides, stack all grids |

---

## Photography

**Photography is essential to American Headhunter.** Hunters need to see the land before they'll lease it. Landowners need to see who's hunting it. Trail cam footage, harvest photos, property aerial shots — these are the substance of the platform, not decoration.

The rule is not "no photography." The rule is **no generic stock.**

### Where photography belongs

| Photography type | Where it appears | Treatment |
|---|---|---|
| **Actual property photos** | Property listings, detail pages, search results | Full-color, edge-to-edge, no filter. Real land only. |
| **Trail camera footage** | Member portal, property detail, harvest context | Timestamp overlays, grid galleries, unfiltered |
| **Harvest photos** | Hunt stories, harvest logs, user profiles | User-uploaded, respectful framing, user-owned |
| **Aerial / drone shots** | Property overviews, listing hero shots | Works especially well — complements the topographic theme |
| **Map overlays** | Property detail pages | Real Mapbox tiles or PostGIS-rendered satellite imagery |
| **Landowner portraits** | About pages, landowner profiles | Editorial — thoughtful composition, natural lighting |
| **Historical / archival** | About, heritage, founder pages | Archival treatment reinforces the editorial brand |
| **Editorial cover photography** | Field Journal articles, featured content | Commissioned work by actual photographers with credit |

### What to avoid specifically

Not all photography. Just the tropes that every hunting-adjacent brand uses and that would make American Headhunter look like every other hunting-adjacent brand:

- **Generic outdoor-lifestyle stock** — bearded guy in Carhartt silhouetted against a sunset, man kneeling with rifle in golden-hour field, Getty Images-style content with no connection to actual inventory
- **Heavily filtered "look" presets** — moody teal-and-orange grading, faux-vintage filters, heavy vignetting
- **Over-staged lifestyle imagery** — product placement of gear brands, models posing with binoculars, unrealistic wardrobe coordination
- **Competing brand imagery** — don't use photos that obviously feature branded gear (Yeti coolers, specific truck brands, competitor apps on phones)
- **Stock imagery that doesn't match your actual inventory** — don't show Montana mountain photos if your listings are all Texas brush country

### Photo treatment to stay on-brand

Real, current photography treated consistently with these rules:

| Rule | Why |
|---|---|
| Slight desaturation (90-95%) | Sits comfortably with the parchment palette; prevents oversaturated photos from fighting the type |
| Landscape crops (16:10, 3:2) preferred | Matches the horizontal cartographic feeling; 1:1 square crops feel social-media generic |
| Subtle dark gradient overlay at bottom of hero images | Supports overlay text without heavy filters |
| No preset "looks" or Instagram filters | Photos should feel documentary, not curated |
| Photographer/source credit where possible | Reinforces editorial positioning |
| Metadata stripped on upload | EXIF data can leak location — handle in upload pipeline |

### Placeholder strategy

The React prototype uses CSS gradient placeholders in property cards (`.prop-img-1` through `.prop-img-6`). **These are placeholders, not the final treatment.** In production every property card has a real landowner-uploaded photo. The gradient fallback exists only for:
- New listings that haven't been photographed yet
- Loading states before the real image fetches
- Archived/inactive listings where photo URLs have expired

When building new components, assume real photos will be present. Design for 16:10 or 3:2 landscape imagery by default.

---

## Icons

**Icons are essential to American Headhunter.** A platform handling harvest logging, trail cameras, SOS alerts, gate codes, applications, payments, and messaging cannot function without them. The rule is not "no icons." The rule is **purposeful icons that match the brand.**

### Icon sources

Three sources, used in order of preference:

**1. Lucide React — 90% of UI icons**
Already included in the stack. Lucide is Feather Icons' successor — clean, geometric, single-stroke icons at 1.5px weight. Matches the sharp-geometry principle of the brand perfectly.

Use Lucide for: navigation, form controls, buttons, dashboards, tables, modals, status indicators, generic actions (search, close, menu, arrow, filter, upload, download, etc.)

```jsx
import { MapPin, Calendar, FileText, Shield } from 'lucide-react';
<MapPin strokeWidth={1.5} size={20} />
```

**2. Custom illustrated SVG — signature brand elements**
For elements where a generic icon isn't distinctive enough. Commissioned or custom-drawn SVG illustrations in a naturalist's field-guide style — like old Audubon illustrations. Single weight, ink-on-parchment feel.

Use custom illustrated icons for:
- **Species identification** (the whole wildlife set — whitetail, turkey, mallard, hog, quail, bass, etc.)
- **Map markers and pins** matching the cartographic theme
- **Signature empty states** (no listings yet, no harvest logged, etc.)
- **Special features** (SOS beacon, compass, topographic markers)

**3. Custom technical/survey icons**
For tools-of-the-trade iconography that reinforces the cartographic brand. Compass points, caliper measurements, GPS pins, section boundary markers. Used sparingly in admin and reports surfaces.

### Icon specifications

| Attribute | Value |
|---|---|
| Stroke width | 1.5px (Lucide default) |
| Size scale | 16, 20, 24 px — inherit text line-height |
| Color | `currentColor` — inherit from parent text color |
| Fill | None (outline only) |
| Alignment | Baseline-aligned with adjacent text |

### Icon color rules

Icons inherit text color via `currentColor` — they're typography-adjacent, not visual ornament. Color them through their parent, not inline.

```jsx
// Good — inherits ink color from text
<button className="text-ink">
  <MapPin /> View Location
</button>

// Bad — hardcoded color breaks theming
<button><MapPin color="#c84c21" /> View Location</button>
```

Exception: status indicators where color is the meaning (blaze for critical alerts, brass for warnings, sage for verified/clear).

### What to avoid specifically

- **Multicolor icons** — break the palette, visually noisy
- **Gradient icons** — break the flat/printed principle
- **3D-style icons, isometric icons** — wrong aesthetic entirely
- **Emoji as standalone UI elements** (🦌 🐗 🎣) — inconsistent across operating systems, too casual for the brand
- **Heavily stylized illustrations competing with typography** — typography is the voice; icons support, don't dominate
- **Icon fonts (Font Awesome, Material Icons)** — SVG is sharper at all sizes and more controllable

### Emoji in brand content

There's one exception to the no-emoji rule: emoji can appear in **user-generated content** (messages, hunt stories, comments, profile bios) because that's the user's voice, not the brand's. The platform UI itself uses SVG icons.

---

## What NOT to Do

The full list of aesthetic anti-patterns that would break the brand. When in doubt, check this list before shipping.

### Typography
- **Do not use system fonts** (Inter, Roboto, Arial, SF Pro, Segoe UI, Helvetica). They have no personality and contradict the editorial direction.
- **Do not italicize entire headlines** — reserve italics for accent words only.
- **Do not center-align body paragraphs.** Body copy is left-aligned. Only headlines, CTAs, and testimonials center.
- **Do not use drop caps, pull quotes, or magazine-article tropes** beyond what's already in the system. The editorial feel comes from typography and structure, not tricks.

### Color
- **Do not invent new colors** when an existing palette variable would work. If you reach for a new color, the design probably isn't solved yet.
- **Do not use blaze orange as a large-area background** — except the stats section where it's a deliberate interruption.
- **Do not use true black** (#000) or pure white (#fff). Use `--ink` and `--bone`. These have subtle warmth that makes everything feel printed rather than digital.

### Geometry & Effects
- **Do not use rounded corners** on containers, cards, or major UI elements. The exception is small badges and pills where 2px radius is acceptable.
- **Do not use soft/blurred shadows.** All shadows are solid, offset, and sharp — like an object physically offset from the paper beneath.
- **Do not add gradients inside UI elements.** Atmospheric gradients are reserved for property images and dark section transitions only.
- **Do not use glassmorphism, neumorphism, or other 2020s UI trend effects.** The brand is timeless-editorial, not trendy.

### Photography & Icons
- See dedicated sections above for full guidance.

### Admin Interfaces
- **Do not force marketing ornaments into admin panels.** Filament admin screens do not need topographic backgrounds, registration marks, or chapter numbering. The brand lives in the typography, palette, and sharp geometry — that's enough.

### Animation
- **Do not use parallax scrolling.**
- **Do not scroll-jack** — the user owns the scroll position.
- **Do not animate every element on intersection.** Reveal animations on page load only, and only on marketing surfaces.
- **Do not use smooth-scroll libraries** — browser-native `scroll-behavior: smooth` is enough.

---

## File Location in Codebase

```
resources/
├── css/
│   └── app.css                    ← Tailwind base + custom properties
├── js/
│   ├── Components/
│   │   ├── Layout/
│   │   │   ├── Nav.jsx
│   │   │   ├── Footer.jsx
│   │   │   └── TopoBg.jsx        ← Topographic contour background
│   │   ├── Property/
│   │   │   ├── PropertyCard.jsx
│   │   │   └── FieldRecord.jsx   ← Signature Field Record card
│   │   └── UI/
│   │       ├── Button.jsx
│   │       ├── ChapterHeader.jsx
│   │       ├── RegMark.jsx        ← Registration marks
│   │       └── CompassRose.jsx
│   └── Pages/
│       ├── Public/
│       │   ├── Landing.jsx
│       │   ├── PropertyDetail.jsx
│       │   └── Auctions.jsx
│       └── ...
└── fonts/                          ← Self-hosted for performance
    ├── Fraunces/
    ├── CrimsonPro/
    └── JetBrainsMono/
```

### Self-host fonts in production
Google Fonts is fine for prototyping. In production, self-host all three typefaces and preload the display font:

```html
<link rel="preload" href="/fonts/Fraunces-VariableFont.woff2" as="font" type="font/woff2" crossorigin>
```

---

## Extending the System

When adding new pages or components, follow the hierarchy:

1. **Does this need a new color?** Almost certainly no. Reach for existing palette.
2. **Does this need a new font?** No. Three typefaces is the system.
3. **Does this need a new pattern?** Check existing components first. If it truly doesn't fit, the new pattern should share the visual DNA:
   - Cartographic / editorial origin
   - Sharp geometry, no rounded corners
   - Monospace technical labels
   - Coordinate/data display where possible
   - Registration marks or dividers anchoring the layout

Any new component should look like it came from the same field journal as the existing ones. If a new design decision would make the existing pages feel out of place, the new decision is wrong.

---

## Reference File

The live React prototype is at `american_headhunter_website.jsx`. All conventions in this document are implemented there and can be referenced directly when building new pages.
