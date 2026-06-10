# Laravel Filament — Admin Backend

Filament 3 powers the admin backend (`/admin`) and landowner management panel (`/manage`). The public-facing portals (`/`, `/apply`, `/member`, `/reports`) are Inertia/React — not Filament. This document defines the panel structure, resource conventions, multi-database considerations, and the design integration approach.

---

## The 5 Portals

American Headhunter has five distinct front-end surfaces:

| Portal | Route prefix | Primary users | Technology |
|---|---|---|---|
| **Public Frontend** | `/` | Unauthenticated visitors | Inertia + React |
| **Customer Portal** | `/apply` | Prospective lessees | Inertia + React |
| **Member Portal** | `/member` | Active lessees | Inertia + React |
| **Admin Backend** | `/admin` | Staff + Platform admins | Filament 3 |
| **Reporting Suite** | `/reports` | Landowners + Admins | Inertia + React (or Filament Reports panel) |

Only `/admin` is a Filament panel. The landowner management surface (`/manage`) is also Filament-based. All other portals are Inertia/React with full brand treatment.

---

## Filament Panels

### Admin Panel — `AdminPanelProvider`

```php
// app/Providers/Filament/AdminPanelProvider.php

public function panel(Panel $panel): Panel
{
    return $panel
        ->id('admin')
        ->path('admin')
        ->login()
        ->colors([
            'primary' => Color::hex('#0a1512'),    // ink
            'danger'  => Color::hex('#c84c21'),    // blaze
            'warning' => Color::hex('#b8934a'),    // brass
            'success' => Color::hex('#6b7856'),    // sage
        ])
        ->font('Fraunces')
        ->brandName('American Headhunter')
        ->brandLogo(asset('images/ah-logo-admin.svg'))
        ->discoverResources(
            in:  app_path('Filament/Admin/Resources'),
            for: 'App\\Filament\\Admin\\Resources',
        )
        ->discoverPages(
            in:  app_path('Filament/Admin/Pages'),
            for: 'App\\Filament\\Admin\\Pages',
        )
        ->discoverWidgets(
            in:  app_path('Filament/Admin/Widgets'),
            for: 'App\\Filament\\Admin\\Widgets',
        )
        ->navigationGroups([
            'Marketplace',
            'Users & Access',
            'Pricing & Promotions',
            'Communications',
            'Safety & Compliance',
            'System',
        ])
        ->authMiddleware([Authenticate::class]);
}
```

Authentication for the admin panel uses the `identity` connection (DB 1, `App\Models\Identity\User`). This is the default connection, so no special configuration is needed for Filament's built-in auth.

### Landowner Panel — `LandownerPanelProvider`

```php
// app/Providers/Filament/LandownerPanelProvider.php

->id('landowner')
->path('manage')
->login()
// Resources are scoped to the authenticated landowner's own data
// Every query scope filters by landowner_user_id = auth()->id()
```

---

## Multi-Database Considerations

Filament assumes a single default connection for its internal tables (login, notifications, etc.). All Filament internals use the `identity` connection, which is the default.

Resources work with Eloquent models. Since models declare their own `$connection`, Filament respects it automatically for CRUD operations on the model's own database. The complication arises with cross-database relationship fields.

### Relationship fields across databases

Filament's `BelongsToSelect`, `BelongsToMany`, and relationship-based form fields assume same-connection Eloquent relationships. For cross-database references, use custom options instead:

```php
// WRONG — will query the wrong database or fail:
BelongsToSelect::make('property_id')
    ->relationship('property', 'title')  // Crosses DB boundary

// CORRECT — populate options via service call:
Select::make('property_id')
    ->label('Property')
    ->options(fn () =>
        app(PropertyService::class)
            ->getAllForSelect()        // Returns ['uuid' => 'Property Title']
    )
    ->searchable()
    ->getSearchResultsUsing(fn (string $search) =>
        app(PropertyService::class)->searchForSelect($search)
    );
```

### Global search

Filament's global search is scoped per-resource. Each resource searches only within its own database via `getGloballySearchableAttributes()`. No cross-database global search.

### Table columns with cross-DB data

Use a computed column populated by service:

```php
TextColumn::make('property_title')
    ->label('Property')
    ->state(fn (Lease $record): string =>
        app(PropertyService::class)->findById($record->property_id)?->title ?? '—'
    );
```

Be careful with performance here — if this runs on 50 rows in a table without caching, it will make 50 service calls. Use batch loading patterns or cache aggressively.

---

## Resource Conventions

```php
<?php

namespace App\Filament\Admin\Resources;

use App\Models\Lease\Lease;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Forms\Form;

class LeaseResource extends Resource
{
    protected static ?string $model           = Lease::class;
    protected static ?string $navigationGroup = 'Marketplace';
    protected static ?string $navigationIcon  = 'heroicon-o-document-text';
    protected static ?int    $navigationSort  = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([/* fields */]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([/* columns */])
            ->filters([/* filters */])
            ->actions([/* row actions */])
            ->bulkActions([/* bulk actions */]);
    }

    // Permission checks via EntitlementService / policy
    public static function canViewAny(): bool
    {
        return auth()->user()->can('view_leases');
    }

    public static function canCreate(): bool
    {
        return false;  // Leases are created through the application workflow, not admin UI
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListLeases::route('/'),
            'view'   => Pages\ViewLease::route('/{record}'),
            'edit'   => Pages\EditLease::route('/{record}/edit'),
        ];
    }
}
```

---

## Admin Panel Resources

Resources live in `app/Filament/Admin/Resources/`.

### Marketplace

| Resource | Model | DB | Purpose |
|---|---|---|---|
| `PropertyResource` | `Property` | 2 | Review listings, moderation, verification status |
| `LeaseResource` | `Lease` | 3 | Lease oversight, status management, signatories |
| `ApplicationResource` | `LeaseApplication` | 3 | Application review and approval workflow |
| `AuctionResource` | `AuctionListing` | 6 | Auction listings and bid history |
| `OutfitterResource` | `OutfitterProfile` | 6 | Outfitter profiles and hunt packages |
| `MarketplaceListingResource` | `MarketplaceListing` | 6 | Gear marketplace items |

### Users & Access

| Resource | Model | DB | Purpose |
|---|---|---|---|
| `UserResource` | `User` | 1 | All users, account types, status, trust scores |
| `ClubResource` | `Club` | 3 | Clubs and membership |
| `VerificationResource` | `IdentityVerification` | 1 | Pending verifications |
| `TrustScoreResource` | `TrustScore` | 1 | View-only trust score details |
| `RoleResource` | `Role` | 1 | Staff roles and permissions |

### Pricing & Promotions

| Resource | Model | DB | Purpose |
|---|---|---|---|
| `MembershipPlanResource` | `MembershipPlan` | 12 | Tier definitions and pricing admin |
| `FeatureEntitlementResource` | `FeatureEntitlement` | 12 | Per-tier feature entitlements |
| `PromotionResource` | `PromotionalPeriod` | 12 | Promotional periods — create/end |
| `PromoCodeResource` | `PromoCode` | 4 | Promo code campaigns |
| `SubscriptionResource` | `Subscription` | 4 | Active subscriptions, view and manage |

`MembershipPlanResource` enforces the immutability rule: editing a plan creates a new `PlanVersion` row rather than modifying the existing one. This is handled in the resource's `handleRecordUpdate()` override.

### Communications

| Resource | Model | DB | Purpose |
|---|---|---|---|
| `MessageThreadResource` | `MessageThread` | 7 | View threads (moderation/support use) |
| `ModerationQueueResource` | `ContentModerationQueue` | 10 | Flagged messages awaiting review |
| `SupportTicketResource` | `SupportTicket` | 7 | Support ticket management |

### Safety & Compliance

| Resource | Model | DB | Purpose |
|---|---|---|---|
| `IncidentResource` | `IncidentReport` | 10 | Safety incidents, disputes |
| `SosEventResource` | `SosEventLog` | 7 | View-only SOS event log (no delete action) |
| `AuditLogResource` | `AuditLog` | 9 | View-only audit trail |
| `DisputeResource` | `LeaseDispute` | 10 | Dispute resolution workflow |

`AuditLogResource` is strictly read-only — no Create, Edit, or Delete actions are registered. The resource uses `ReadOnlyResource` pattern: only `ListRecords` and `ViewRecord` pages.

### System

| Resource / Page | Purpose |
|---|---|
| `FeatureFlagResource` | Toggle feature flags live (invalidates Valkey cache on save) |
| `TenantSettingResource` | Platform-wide configuration |
| `Dashboard` (page) | Key metrics — MRR, active leases, new signups |
| `SystemHealth` (page) | Database, Valkey, storage, queue health status |
| `EtlStatus` (page) | ETL job run history and last-run timestamps |
| `RevenueDashboard` (page) | Financial metrics and payout status |

---

## Permissions Model

Access control operates at three levels:

1. **Panel access** — is the user allowed into `/admin` at all? Controlled by `AdminPanelProvider::gate()`.
2. **Resource access** — which resources appear? Controlled by `canViewAny()`, `canView()`, etc., delegating to Laravel policies.
3. **Action access** — specific actions gated (e.g., only `pricing_admin` role can create new plan versions).

```php
// Panel gate — restrict /admin to staff only:
public function register(): void
{
    Filament::auth()->gate(function (User $user): bool {
        return $user->hasRole('platform_admin')
            || $user->hasRole('platform_staff');
    });
}

// Resource-level:
public static function canViewAny(): bool
{
    return auth()->user()->can('view_leases');
}

public static function canCreate(): bool
{
    return auth()->user()->can('create_leases');
}

// Entitlement check example:
public static function canAccessFeature(): bool
{
    return app(EntitlementService::class)->can(auth()->user(), 'feature_key');
}
```

### Prohibited Actions in Admin

These actions are never exposed in Filament regardless of role:

- Bulk permanent (hard) deletion of user-facing records — soft-delete only, with audit
- Reading or displaying raw encryption key values
- Accessing or exporting raw payment method details (Stripe IDs only in UI)
- Modifying `SosEventLog` records — no edit or delete actions registered
- Modifying `AuditLog` records — read-only resource
- Changing another user's authentication credentials directly

---

## Widgets

Dashboard widgets in `app/Filament/Admin/Widgets/`:

| Widget | Data source | Notes |
|---|---|---|
| `ActiveLeasesStat` | DB 3 `lease` | Live count of active leases |
| `MrrStat` | DB 8 `analytics` (read-only) | Monthly recurring revenue |
| `NewSignupsChart` | DB 8 `analytics` | Signup trend over 30 days |
| `PendingVerificationsStat` | DB 1 `identity` | Verifications awaiting review |
| `ModerationQueueStat` | DB 10 `incidents` | Flagged content count |
| `RecentSosEvents` | DB 7 `communications` | Last 5 SOS events (view-only) |
| `PromotionPerformance` | DB 8 `analytics` | Active promo claim/conversion stats |

Widgets reading from DB 8 use the `analytics` (read-only) connection. Time-sensitive widgets (moderation queue, SOS events) read from operational databases directly.

---

## Design Integration

Filament is used at **minimum amplitude** — its defaults are respected and the brand is applied through tokens only. No marketing ornaments, topographic backgrounds, chapter numbering, or editorial layouts in admin screens.

**Customized:**
- Color tokens (ink, blaze, brass, sage) via `->colors([...])`
- Heading font (Fraunces) via `->font('Fraunces')`
- Monospace for IDs, timestamps, coordinates — custom column styling

**Left as Filament defaults:**
- Table layouts, filters, pagination
- Form field rendering
- Navigation patterns
- Modal and notification behavior

```css
/* resources/css/filament/admin/theme.css */
@import '/vendor/filament/filament/resources/css/theme.css';

@layer base {
    :root {
        --font-family-heading: 'Fraunces', Georgia, serif;
        --font-family-mono: 'JetBrains Mono', monospace;
    }
}

.fi-ta-text-item-label.font-mono {
    font-family: var(--font-family-mono);
    font-size: 0.8125rem;
}
```

Build the admin theme after any CSS changes:

```bash
php artisan make:filament-theme admin   # one-time setup
npm run build
```

---

## Filament + Inertia Coexistence

Filament (Blade/Livewire) and Inertia/React coexist in the same Laravel app without conflict because they own separate route groups:

- `/admin`, `/manage` → Filament
- `/`, `/apply`, `/member`, `/reports` → Inertia/React

They share the same models, services, authentication, and Valkey sessions. A staff member's session is valid on both surfaces. The `InjectDatabaseContext` middleware runs on all routes, setting RLS context for all connections.

---

## File Structure

```
app/Filament/
├── Admin/
│   ├── Resources/
│   │   ├── UserResource.php
│   │   ├── PropertyResource.php
│   │   ├── LeaseResource.php
│   │   ├── ApplicationResource.php
│   │   ├── AuctionResource.php
│   │   ├── BillingResource.php
│   │   ├── IncidentResource.php
│   │   ├── AuditLogResource.php          -- read-only, no Create/Edit/Delete
│   │   ├── SosEventResource.php          -- read-only
│   │   ├── FeatureFlagResource.php
│   │   ├── MembershipPlanResource.php
│   │   ├── PromotionResource.php
│   │   ├── SubscriptionResource.php
│   │   └── SupportTicketResource.php
│   ├── Pages/
│   │   ├── Dashboard.php
│   │   ├── SystemHealth.php
│   │   ├── EtlStatus.php
│   │   └── RevenueDashboard.php
│   └── Widgets/
│       ├── ActiveLeasesStat.php
│       ├── MrrStat.php
│       ├── NewSignupsChart.php
│       ├── PendingVerificationsStat.php
│       ├── ModerationQueueStat.php
│       └── RecentSosEvents.php
│
└── Landowner/
    └── Resources/
        ├── MyPropertyResource.php        -- scoped to landowner's properties
        ├── MyLeaseResource.php           -- scoped to landowner's leases
        ├── MyApplicationResource.php     -- scoped to landowner's incoming apps
        ├── MyPayoutResource.php          -- scoped to landowner's payouts
        └── MyTrailCameraResource.php     -- scoped to landowner's cameras
```
