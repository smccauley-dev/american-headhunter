# Membership Tiers & Pricing

This document defines the membership structure for American Headhunter, how tiers are configured, and how feature access is determined. **Critical principle: all pricing, all tier definitions, and all feature entitlements are admin-configurable via the database — never hardcoded in application code.**

---

## Core Principle — Database-Driven Pricing

Pricing and feature access live in three admin-editable tables in DB 12 Platform:

- **`membership_plans`** — tier definitions (name, price, billing interval, display order)
- **`feature_entitlements`** — feature flags per tier (what each tier can do)
- **`promotional_periods`** — free periods, launch promos, seasonal discounts

The application **never** contains hardcoded prices or tier rules. Every price change, new tier launch, feature gate, or promotional window is made through the admin backend and takes effect immediately after cache invalidation.

This means:
- Marketing can launch "Labor Day Sale — 30% off Ranch tier" without a code deploy
- Pricing experiments can run A/B tests by segmenting users to different plan variants
- Veteran discounts, loyalty discounts, and partnership discounts are all configurable
- Grandfathered pricing for early customers is handled by plan version references, not code logic

---

## Initial Tier Structure (Defaults — Fully Configurable)

These are the **launch defaults**. All values below can be changed in the admin backend after launch.

### Hunter Tiers

| Tier | Monthly | Annual | Notes |
|---|---|---|---|
| **Scout** (Free) | $0 | $0 | Free forever — browse and limited applications |
| **Sportsman** | $9 | $89 | Primary paid tier |
| **Outfitter** | $19 | $189 | Premium hunter tier |
| **Veteran** | $0 | $0 | Free via ID.me verification |

### Landowner Tiers

| Tier | Monthly | Annual | Platform Fee | Notes |
|---|---|---|---|---|
| **Homestead** (Free) | $0 | $0 | 5% | Free forever — 1 active listing |
| **Ranch** | $29 | $290 | 3% | Primary paid tier |
| **Estate** | $79 | $790 | 2% | Unlimited listings, premium placement |

### Club Tiers

| Tier | Monthly | Annual | Notes |
|---|---|---|---|
| **Basic Club** | $0 | $0 | Free forever — basic club features |
| **Premium Club** | $19 | $189 | Shared calendar, expense splitting, member management |

### Other Account Types

| Type | Monthly | Commission | Notes |
|---|---|---|---|
| **Outfitter** | $49/mo | 10% on bookings | No free tier — commercial service providers |
| **Consultant** | $0 | 15% on fees | Free to list, commission-based revenue |
| **Marketplace Seller** | $0 | 8% per sale | Free to list, transaction-based revenue |

---

## Feature Entitlements — What Each Tier Gets

### Hunter Tier Features

| Feature | Scout (Free) | Sportsman | Outfitter | Veteran |
|---|:-:|:-:|:-:|:-:|
| Browse all listings | ✓ | ✓ | ✓ | ✓ |
| Saved searches | 3 | Unlimited | Unlimited | Unlimited |
| Email alerts | ✓ | ✓ | ✓ | ✓ |
| Lease applications per season | 3 | Unlimited | Unlimited | Unlimited |
| Messaging with landowners | Standard | Standard | Priority | Standard |
| Waitlist priority notifications | — | ✓ | ✓ | ✓ |
| Trail camera integration | — | ✓ | ✓ | ✓ |
| Harvest logging | Basic | Full | Full | Full |
| Digital ID card | — | ✓ | ✓ | ✓ |
| Offline PWA access | — | ✓ | ✓ | ✓ |
| Background checks included | 0 | 1/year | 3/year | 1/year |
| Early access to new listings | — | — | 48hr early | — |
| Guided hunt booking discount | — | — | 5% | — |
| Concierge messaging | — | — | ✓ | — |
| Profile trust badge | Standard | Enhanced | Premium | Veteran |

### Landowner Tier Features

| Feature | Homestead (Free) | Ranch | Estate |
|---|:-:|:-:|:-:|
| Active listings | 1 | 5 | Unlimited |
| Search visibility | Standard | Boosted | Top placement |
| Lease templates | Basic | Custom | Attorney-reviewed |
| Platform fee on bookings | 5% | 3% | 2% |
| Advanced analytics | — | ✓ | ✓ |
| Property response metrics | ✓ | ✓ | ✓ |
| Background check credits | 0 | 3/year | 10/year |
| Financial reporting | Basic | Full | Full + Custom |
| 1099 generation | ✓ | ✓ | ✓ |
| Landowner messaging | Standard | Priority | Concierge |
| Photo uploads per listing | 10 | 30 | Unlimited |
| Video uploads | — | 3 | Unlimited |
| Virtual tour | — | — | ✓ |
| White-label option | — | — | ✓ (contact for pricing) |
| Dedicated support | — | — | ✓ |
| API access | — | — | ✓ |

### Club Tier Features

| Feature | Basic (Free) | Premium |
|---|:-:|:-:|
| Member roster management | ✓ | ✓ |
| Lease properties as a club | ✓ | ✓ |
| Bylaws storage | ✓ | ✓ |
| Shared hunt calendar | — | ✓ |
| Stand assignment tool | — | ✓ |
| Expense splitting | — | ✓ |
| Member voting | — | ✓ |
| Guest pass management | Basic | Full |
| Member announcements | — | ✓ |
| Financial reporting | — | ✓ |
| Shared trail camera access | — | ✓ |

---

## Admin Backend — Pricing Configuration

All pricing is managed through Filament admin resources. Staff with the `pricing_admin` permission can:

### Plan Management
```
Admin → Pricing → Membership Plans

    [Edit Plan: Sportsman Hunter]
    ─────────────────────────────
    Plan Key:           sportsman_hunter      [readonly]
    Account Type:       hunter               [dropdown]
    Display Name:       Sportsman
    Description:        [text area]
    Monthly Price:      $9.00
    Annual Price:       $89.00
    Billing Interval:   Monthly / Annual / Both
    Sort Order:         2
    Display on public:  ☑ Yes
    Currently active:   ☑ Yes
    Launched:           2025-03-15
    Deprecated:         (empty)
    Notes:              Internal notes, visible to admin only

    [Stripe Price IDs]
    Monthly Price ID:   price_1AbC...
    Annual Price ID:    price_1DeF...

    [ Save Changes ]    [ Duplicate Plan ]    [ Deprecate ]
```

### Feature Entitlements
```
Admin → Pricing → Feature Entitlements

    Plan: Sportsman
    ─────────────────────────────
    ┌──────────────────────────────────────────┬───────┐
    │ Feature Key                              │ Value │
    ├──────────────────────────────────────────┼───────┤
    │ saved_searches_limit                     │  -1   │ (-1 = unlimited)
    │ lease_applications_per_season            │  -1   │
    │ background_checks_per_year               │   1   │
    │ trail_camera_integration                 │ true  │
    │ digital_id_card                          │ true  │
    │ offline_pwa                              │ true  │
    │ early_listing_access_hours               │   0   │
    │ guided_hunt_discount_pct                 │   0   │
    │ concierge_messaging                      │ false │
    │ trust_badge_level                        │enhanced│
    └──────────────────────────────────────────┴───────┘

    [ Save Entitlements ]    [ Copy to Another Plan ]
```

Every entitlement is a key-value pair. The application reads entitlements via a helper:

```php
// In any service or controller
if (app(EntitlementService::class)->can($user, 'trail_camera_integration')) {
    // feature available
}

$limit = app(EntitlementService::class)->limit($user, 'saved_searches_limit');
if ($limit === -1 || $currentCount < $limit) {
    // allow action
}
```

### Promotional Periods
```
Admin → Pricing → Promotional Periods

    [ + New Promotion ]

    Active Promotions:
    ┌──────────────────────────────────────────────────────────────┐
    │ Founding Landowner — 12mo Ranch Free                         │
    │ Target:  New landowners                                       │
    │ Runs:    2025-03-15 → 2025-12-31                             │
    │ Limit:   First 500 landowners                                 │
    │ Status:  Active — 183 of 500 claimed                          │
    │ [ Edit ]  [ Pause ]  [ End Now ]                             │
    └──────────────────────────────────────────────────────────────┘
    ┌──────────────────────────────────────────────────────────────┐
    │ Landowner Honeymoon — 90 Days Ranch Free                     │
    │ Target:  All new landowners                                   │
    │ Runs:    Permanent — no end date                              │
    │ Limit:   Once per account                                     │
    │ Status:  Active — applied automatically                       │
    │ [ Edit ]  [ Pause ]                                          │
    └──────────────────────────────────────────────────────────────┘
    ┌──────────────────────────────────────────────────────────────┐
    │ Off-Season List-Now-Pay-Later                                 │
    │ Target:  New landowners March–July 2026                      │
    │ Runs:    2026-03-01 → 2026-07-31                             │
    │ Benefit: No subscription charge until Sept 1, 2026           │
    │ Status:  Scheduled                                            │
    │ [ Edit ]  [ Delete ]                                         │
    └──────────────────────────────────────────────────────────────┘
```

---

## Promotional Period Types

### 1. Founding Landowner Promotion (Launch)
- **What:** Ranch tier free for 12 months
- **Target:** First 500 landowners to sign up and publish a verified listing
- **Triggers:** Landowner account creation → listing published → verification passed
- **Tracking:** Counter in `promotional_periods.claim_count`; promo auto-ends when `claim_limit` hit
- **Expiration:** After 12 months, account auto-converts to paid Ranch unless downgraded
- **Grandfathered status:** If pricing changes during the 12 months, they keep the original terms

### 2. Landowner Honeymoon (Permanent)
- **What:** Ranch tier features free for 90 days from signup
- **Target:** Every new landowner
- **Triggers:** Landowner account creation + listing published
- **Purpose:** Chicken-and-egg solution — landowners won't pay before proving the platform works
- **Expiration:** Day 91, account auto-converts to Homestead (free tier with 1 listing) unless they upgrade
- **If they upgrade early:** Promo ends, full Ranch billing begins, gets any upgrade credit

### 3. Seasonal Promotions (Ongoing)
- **What:** Configurable — "List now, pay nothing until September," "30% off annual plans," etc.
- **Target:** Date-bound new or existing users
- **Triggers:** Admin-defined — date range, account type, specific user segments
- **Purpose:** Drive signups during slow calendar windows

### 4. Referral Rewards
- **What:** Configurable referral bonuses — "Refer 3 landowners, get a month free"
- **Target:** Existing users referring new users
- **Triggers:** Referred user signs up and completes their own onboarding
- **Credit:** Applied to next billing cycle

### 5. Veteran Program
- **What:** Free forever for verified veterans (hunter tier); configurable discount for other types
- **Target:** Users who complete ID.me or DD-214 verification
- **Triggers:** Verification success
- **Expiration:** None — permanent once verified

### 6. Promo Codes
- **What:** Single-use or reusable discount codes
- **Target:** Specific campaigns (launch partners, influencers, industry shows)
- **Fields:** Code, discount type (% or $), applies to (which tiers), valid dates, usage limits
- **Existing:** DB 4 `promo_codes` table handles this

---

## User-Facing Pricing Display

The pricing page at `americanheadhunter.com/pricing` **reads from DB 12** at render time (with aggressive Valkey caching at 15-minute TTL).

```
┌──────────────────────────────────────────────────┐
│                                                   │
│   Choose Your Plan                                │
│                                                   │
│   Hunters │ Landowners │ Clubs                   │
│   ════════                                        │
│                                                   │
│   ┌───────────┐  ┌───────────┐  ┌───────────┐   │
│   │  SCOUT    │  │SPORTSMAN  │  │ OUTFITTER │   │
│   │    FREE   │  │  $9/mo    │  │ $19/mo    │   │
│   │           │  │  $89/yr   │  │ $189/yr   │   │
│   │           │  │           │  │           │   │
│   │  • Browse │  │  • All Scout│  • All Sportsman│
│   │  • Save 3 │  │  • Unlimit│  │  • Early │   │
│   │    searches│ │    applics │  │    access│   │
│   │  • Email  │  │  • Trail  │  │  • Disc- │   │
│   │    alerts │  │    cams   │  │    ounts │   │
│   │           │  │  • Digital│  │  • Con-  │   │
│   │           │  │    ID card│  │    cierge│   │
│   │           │  │           │  │           │   │
│   │  [Get     │  │  [Upgrade │  │  [Upgrade │   │
│   │  Started] │  │    Now]   │  │    Now]  │   │
│   └───────────┘  └───────────┘  └───────────┘   │
│                                                   │
│   ─── Are you a veteran? ─────────────────      │
│   Scout + Sportsman features free forever        │
│   with ID.me verification. [ Verify now → ]      │
│                                                   │
└──────────────────────────────────────────────────┘
```

**Toggle between Monthly / Annual billing** — the display auto-updates with pricing pulled from `membership_plans.monthly_price` or `annual_price`.

**Currently-active promotions appear as callouts** on the pricing page:
```
⚡ Founding Landowner Special
Lock in Ranch tier FREE for 12 months.
Only 183 of 500 spots claimed. [ Learn more → ]
```

---

## Billing Mechanics

### Subscription Lifecycle

1. **Trial/Free period** — no payment taken yet
2. **First billing cycle** — Stripe charges based on plan interval
3. **Ongoing** — auto-renews at each cycle
4. **Grace period** — 3 days for failed payments before feature restriction
5. **Downgrade** — user remains at paid features until period end, then drops to free
6. **Cancellation** — immediate stop of auto-renewal, access continues until period end

### Plan Changes

- **Upgrade mid-cycle:** Stripe prorates, charge immediately, apply new features instantly
- **Downgrade mid-cycle:** Scheduled for end of current period, no refund, access continues until period end
- **Same-tier billing switch (monthly → annual):** Prorates, applies at next renewal

### Feature Enforcement

Every feature-gated action runs through `EntitlementService`:

```php
// Controller example — applying for a lease
public function apply(Request $request, Property $property)
{
    $user = $request->user();
    $entitlements = app(EntitlementService::class);

    $applicationsThisSeason = $user->applications()
        ->where('created_at', '>=', currentSeasonStart())
        ->count();

    $limit = $entitlements->limit($user, 'lease_applications_per_season');

    if ($limit !== -1 && $applicationsThisSeason >= $limit) {
        throw new EntitlementExceededException(
            'Your plan allows ' . $limit . ' applications per season. ' .
            'Upgrade to continue.'
        );
    }

    // proceed with application
}
```

All entitlement checks are cached in Valkey with 5-minute TTL to keep the database load low.

---

## Grandfathering & Plan Versioning

When a plan's pricing or entitlements change, existing subscribers are handled via `plan_version` references:

- Each `subscription` row references `plan_version_id`, not just the plan
- `plan_versions` table stores immutable snapshots of plan terms at each change
- Raising prices on new subscribers does not retroactively charge existing subscribers
- Admins can optionally migrate existing subscribers to new version via bulk operation

Example flow:
1. Admin increases Ranch tier from $29 to $35/mo
2. New signups use v2 ($35/mo)
3. Existing subscribers stay on v1 ($29/mo) until admin explicitly migrates them or their plan is deprecated
4. `plan_version_id` in `subscriptions` table tracks which version each subscriber is on

---

## Reports & Analytics

Admin dashboard surfaces key subscription metrics in real time:

- **Active subscriptions** by plan
- **MRR** by plan, total
- **Churn rate** (last 30 days) by plan
- **Trial → paid conversion rate** by plan
- **Upgrade rate** (free → paid)
- **Downgrade rate** by plan
- **Promo code usage** and conversion
- **Promotional period performance** — claim counts, conversion to paid
- **Lifetime value** by acquisition cohort
- **Plan distribution** — what % of users on each tier

All derived from DB 8 Analytics tables, populated by nightly ETL from DB 4 Billing.
