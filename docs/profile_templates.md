# Profile Templates — CMS Theming for Member/Public Profiles

Status tracker and design notes for the **Profile Templates** feature: an admin-driven
CMS layer that controls the look and module layout of the member profile pages
(currently `Hunter.tsx`, later Angler/Outfitter), without code changes.

Branch: `feature/profile-templates`
Owner: staff (admin backend) · DB: 12 (`platform`) · Cache: Valkey Cluster 2

---

## Why this exists

The profile page has hard-coded decorations (coffee-ring stain, registration marks,
topographic background) and a fixed set of content modules (About, Contact, Social,
Photos, Gear, Activity). Today changing any of that is a code edit + deploy.

Profile Templates moves those choices into DB 12 so staff can, per **profile type**
(hunter / angler / outfitter):

- toggle decorations on/off (and tune coffee-stain opacity),
- toggle which content modules appear,
- *(Slice 2)* reorder modules and adjust the theme colors,

…all from a Filament admin screen, taking effect for every profile of that type.

---

## Scope ladder (context — not all built)

| Tier | What it covers | State |
|---|---|---|
| **Tier 1** | Single global on/off flags (feature-flag style). | superseded |
| **Tier 2** | Per-profile-type config: decorations, module enable + order, theme tokens. **← we are building this** | building |
| **Tier 3** | Per-*member* overrides on top of the type template; template marketplace; live preview. | future |

This document tracks **Tier 2**, delivered as two shippable slices.

---

## Architecture decisions

- **Staff-global, keyed by profile type.** One template row per public profile type:
  `hunter`, `angler`, `outfitter`. Every profile of that type renders from its type's
  published config. Per-member customization is explicitly a **Tier 3** extension and
  is out of scope here.
- **Config lives in DB 12 JSONB**, never hard-coded. Mirrors the platform convention
  of database-driven config (feature flags, tenant settings, plans).
- **Draft → Publish, single table.** Unlike `plan_versions` / `notification_template_versions`
  (which keep immutable version rows for *legal grandfathering* of existing subscribers),
  profile theming is cosmetic and has no grandfathering need — everyone gets the current
  published look. So we use one table with two JSONB columns: `draft_config` (work in
  progress) and `published_config` (live). Admin edits the draft, then **Publish** copies
  draft → published. Version-history/rollback is deliberately deferred (Tier 3) to avoid
  over-engineering cosmetic config.
- **Defaults-merge in the service.** The rendered config is always
  `DEFAULT_TEMPLATE` deep-merged with the stored `published_config`, so adding a new
  decoration/module key in code never breaks existing rows.
- **Cache in Valkey Cluster 2**, invalidated on publish.

---

## Data model (DB 12 `platform`)

Table: `profile_templates`

| Column | Type | Notes |
|---|---|---|
| `id` | UUID PK | `gen_random_uuid()` |
| `profile_type` | VARCHAR(30) | UNIQUE — `hunter` / `angler` / `outfitter` |
| `name` | VARCHAR(100) | display name |
| `description` | TEXT | nullable |
| `draft_config` | JSONB | editable working copy |
| `published_config` | JSONB | live, read by profile pages |
| `published_at` | TIMESTAMPTZ | nullable |
| `published_by_user_id` | UUID | References DB 1 (Identity) `users.id`; nullable |
| `created_at` / `updated_at` | TIMESTAMPTZ | `updated_at` via `trigger_set_updated_at()` |

No soft-delete: these are fixed system rows, not user content.

### Config JSONB schema

```jsonc
{
  "decorations": {
    "coffee_stain":       { "enabled": true, "opacity": 0.45 },
    "registration_marks": { "enabled": true },
    "topo_background":    { "enabled": true }
  },
  "modules": {
    "about":    { "enabled": true, "order": 1 },
    "contact":  { "enabled": true, "order": 2 },
    "social":   { "enabled": true, "order": 3 },
    "photos":   { "enabled": true, "order": 4 },
    "gear":     { "enabled": true, "order": 5 },
    "activity": { "enabled": true, "order": 6 }
  },
  "theme": { "accent": "#C84C21", "paper": "#F8F4EB", "ink": "#0A1512" }
}
```

- `order` and `theme` are in the schema from day one but are **only honored in Slice 2**.
- `about` is always rendered (admin toggle is disabled for it); the `security` tab is a
  core account function and is never templated.
- Module **enable** (admin: "does this section exist for this profile type") is distinct
  from member **visibility** public/private (member-controlled, unchanged).
- Defaults equal the current hard-coded appearance, so seeding causes **no visual change**.

---

## Slice 1 — Decorations + module toggles

Goal: admin can toggle the three decorations (and coffee-stain opacity) and the optional
modules per profile type; the hunter profile honors it. No reorder, no theme colors.

- [x] Migration `platform/2026_06_13_000001_create_profile_templates_table.php`
      (table + `set_updated_at` trigger + seed 3 rows inline — matches `tenant_settings`
      convention, so no separate seeder needed)
- [x] Model `App\Models\Platform\ProfileTemplate` (casts draft/published JSONB to array)
- [x] Service `App\Services\Platform\ProfileTemplateService`
      (`DEFAULT_TEMPLATE`, `getPublishedConfig(type)` with defaults-merge + cache,
      `getDraftConfig`, `saveDraft`, `publish`, `invalidate`)
- [x] Filament resource `ProfileTemplates` under **System** group: lists the 3 rows,
      edit page with decoration toggles + opacity + module enable toggles; **Save** stores
      draft, **Publish** header action promotes draft → live. No create/delete (fixed rows).
- [x] `ProfileController::show` passes `template` (published `hunter` config) to the page
- [x] `Hunter.tsx`: gate `CoffeeStain01` (+ opacity), registration marks, `topo-bg`, and
      optional module tabs (contact/social/photos/gear/activity) on the template config
- [x] Sync to WSL2 · `npm run build` · `php -l` · commit + push (2026-06-13, commit 98c1227)

> Public-side note: `HunterPublicProfileController` / `Public/HunterPublicProfile` is a
> separate, simpler layout that does not carry these decorations/module tabs, so wiring
> it is deferred to Slice 2 (extend templating to other surfaces) to avoid an unused prop.

## Slice 2 — Module ordering + theme tokens

Goal: admin can reorder modules and set theme colors; tokenize the hex in `Hunter.tsx`;
extend templating to Angler and Outfitter profile pages.

- [x] Filament: per-module `order` number inputs (writes `order`) + 3 theme color pickers
      (accent/paper/ink). Numeric inputs over drag-drop — modules are a fixed keyed map,
      not a list, so a repeater would force a map↔list conversion. Drag-drop = future polish.
- [x] Service honors `order` — `getPublishedConfig` already merges `order`/`theme` from
      DEFAULT_TEMPLATE; the page sorts tabs by `order`. No service change needed.
- [x] `Hunter.tsx`: build `orderedTabs` by filtering enabled modules and sorting by
      `order`; `security` always appended last. Tab bar maps over the ordered list.
- [x] Tokenize literal hex → CSS custom properties (`--ah-accent/--ah-paper/--ah-ink`)
      set on the page wrapper from `theme`; all 3 brand hexes replaced with `var(...)`.
- [x] Wire `HunterPublicProfileController` (passes `template` in both private + public
      branches) + apply theme tokens & reg-mark gate to `Public/HunterPublicProfile`.
- [N/A] Angler / Outfitter profile pages — **do not exist yet** (only `Hunter.tsx`).
      Template rows are seeded for all three types; their frontends will consume the
      template when built. Nothing to wire today.
- [x] Build · lint · commit + push

---

## Future (Tier 3 — not scheduled)

- Per-member overrides layered on the type template.
- Version history + rollback (re-introduce immutable version rows if demand appears).
- Admin live preview of a profile with the draft config.
- Additional decoration assets (e.g. `coffee-stain-02.png`, torn-edge, tape).
