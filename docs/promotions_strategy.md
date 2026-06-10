# Promotions Strategy

This document covers every type of promotional period, free-listing window, launch incentive, and discount mechanism available in American Headhunter. **Every promotion described here is admin-configurable via the Filament backend — nothing is hardcoded.**

---

## Why Promotions Matter Here

American Headhunter is a two-sided marketplace with severe chicken-and-egg problems:
- **Hunters won't join without listings**
- **Landowners won't list without hunters**

Promotions are how we break the deadlock. They lower the risk for both sides during the critical early stages — landowners list for free to prove the platform works; hunters sign up free to explore what's available. Revenue comes from transaction fees, not subscriptions.

Subscription tiers are **commitment signals** that drive better behavior and cover platform costs. The real revenue engine is transaction volume flowing through the platform.

---

## Promotion Types

### Type 1 — Founding Landowner Promotion (Launch)

**What:** Ranch-tier landowner subscription free for 12 months
**Target:** First 500 landowners to sign up, publish a verified listing, and complete Stripe Connect
**Purpose:** Aggressively build supply-side inventory at launch
**Scarcity signal:** "Only 500 spots, 183 claimed" displayed publicly

**Mechanics:**
- Auto-applied to qualifying signups while slots remain
- Tracked by counter in `promotional_periods.claim_count`
- Promo auto-deactivates when `claim_limit` hit
- After 12 months, account auto-converts to paid Ranch tier
- Users can downgrade to Homestead before conversion to avoid charges
- Grandfathered pricing: if Ranch tier price changes during the 12 months, they keep the original Ranch features at $0

**Tracking:**
- Show public claim counter on landing page and pricing page
- Show personal status in landowner dashboard ("Your Founding Landowner benefit expires Sept 12, 2026")
- Email reminders at day 335, 355, 365

**Admin configuration:**
- Start date, end date (optional — ends at claim limit regardless)
- Claim limit
- Which tier to grant (Ranch by default, but configurable)
- Duration of free period (12 months default)
- Whether to auto-convert to paid or auto-downgrade to free

---

### Type 2 — Landowner Honeymoon (Permanent)

**What:** Ranch-tier features free for 90 days from first listing publication
**Target:** Every new landowner, permanently
**Purpose:** Prove the platform works before asking for payment; solves early-adopter risk

**Mechanics:**
- Not time-bound — this runs forever
- Triggered when a landowner publishes their first verified listing (not signup)
- Not cumulative with Founding Landowner promotion — the user gets whichever is more generous
- After 90 days, auto-converts to Homestead (free tier, 1 listing limit) unless user upgrades
- If user's active listings exceed Homestead's 1-listing limit on conversion, all listings remain visible but warning banner appears prompting upgrade

**Why 90 days specifically:**
- Most hunting leases run September–February — 90 days is enough to cycle through application, negotiation, contract, and first hunts
- Gives landowner real revenue experience before asking them to pay
- Short enough to maintain urgency to upgrade

**Admin configuration:**
- Duration (default 90 days)
- Which tier to grant (Ranch default)
- Post-expiration behavior (downgrade to free vs. auto-charge)
- Grace period for failed post-promo conversions

---

### Type 3 — Seasonal Promotions

**What:** Configurable date-bound promotions
**Target:** Segments defined by account type, date range, region, or custom criteria
**Purpose:** Drive signups during slow calendar windows; spike inventory before key seasons

**Example campaigns:**

| Name | Window | Offer | Target |
|---|---|---|---|
| Off-Season List-and-Lock | Mar 1 – Jul 31 | Free Ranch tier until Sep 1 | New landowners |
| Labor Day Launch | Aug 25 – Sep 5 | 30% off annual plans | All new signups |
| New Year New Lease | Jan 1 – Jan 31 | First month free | Hunters |
| Veterans Week | Nov 5 – Nov 12 | Extended benefits beyond standard veteran program | Verified veterans |
| Turkey Season Kickoff | Feb 1 – Mar 15 | Sportsman tier free 60 days | Hunters with turkey species interest |

**Mechanics:**
- Fully admin-configurable — name, target, benefit, dates, eligibility rules
- Eligibility rules can include: account type, state, signup date, current plan, species interest, veteran status, referral source
- Multiple seasonal promos can run simultaneously for different segments
- User can only claim each promo once (unless explicitly configured otherwise)

**Admin configuration:**
- Full eligibility rule builder (Filament drag-and-drop or JSON editor)
- Preview mode shows affected user count before launching
- Can pause or end any active promo instantly
- Scheduled promos visible in admin calendar view

---

### Type 4 — Referral Rewards

**What:** Credit for referring new users who complete onboarding
**Target:** Existing active users
**Purpose:** User-driven growth at lower CAC than paid acquisition

**Example structures (admin-configurable):**
- "Refer 1 landowner, get $25 credit"
- "Refer 3 hunters, get 1 month free"
- "Refer 10 landowners, get lifetime Sportsman tier"

**Mechanics:**
- Each user gets a unique referral code in their account settings
- Referral attribution via URL parameter or code entry at signup
- Credit posts to referrer's account when referred user completes onboarding (not at signup — prevents abuse)
- Referred user may also get a welcome bonus (configurable)
- Tiered referral programs possible (better rewards for more referrals)

**Admin configuration:**
- Referral reward per referral
- Completion trigger (signup / first payment / 30 days active / first lease signed)
- Referrer reward type (credit / free months / upgraded tier)
- Referred user bonus (optional)
- Referral cap per user
- Fraud detection thresholds

---

### Type 5 — Veteran Program (Permanent)

**What:** Free or discounted access for verified veterans
**Target:** Users who complete ID.me or DD-214 verification
**Purpose:** Honor military service; align with hunting community values

**Current defaults:**
- Hunter accounts: Free Sportsman-tier features forever (currently called "Veteran" tier)
- Landowner accounts: Configurable discount on chosen tier (default 20% off)
- Outfitter accounts: Configurable discount on monthly fee (default 20% off)
- Applied automatically on successful verification

**Mechanics:**
- Verification via ID.me integration (preferred — one-tap for veterans already in the system)
- DD-214 document upload as fallback
- Routes through Module AX in the scope
- Permanent once verified — `users.is_veteran = true` and `users.veteran_verified_at` set
- Can be combined with other promotions (configurable per promo)

**Admin configuration:**
- Discount amount per account type
- Whether veteran status stacks with other promos
- Verification methods accepted
- Renewal requirements (default: none — lifetime)

---

### Type 6 — Promo Codes

**What:** Short codes for targeted campaigns
**Target:** Specific audiences — launch partners, influencers, industry show attendees, podcast listeners
**Purpose:** Attribution tracking + targeted discounts

**Existing infrastructure:** DB 4 already has a `promo_codes` table. This integrates with the new `promotional_periods` system.

**Example codes:**

| Code | Benefit | Target | Limit |
|---|---|---|---|
| FOUNDER2025 | 12 months free Ranch | Early adopters | 500 uses |
| PODCAST50 | 50% off annual plan | Podcast listeners | Unlimited, 1 per user |
| SHOTSHOW | First 3 months free | SHOT Show 2026 attendees | 200 uses |
| VETERAN24 | Waives standard verification wait | Veteran verification | Time-limited |

**Mechanics:**
- Code entered at signup or in billing settings
- Code can be single-use, N-use, or unlimited
- Can be user-specific (one-time code for VIP users)
- Stacks or doesn't stack with other promotions (configurable)

**Admin configuration:**
- Full code generator with uniqueness guarantee
- Benefit type: % off / $ off / free period / tier grant
- Valid date range
- Usage limits (total, per-user, per-account-type)
- Account type restrictions
- Minimum plan requirement
- Stackability rules

---

## Promotion Precedence & Stacking

When multiple promotions could apply to a user, which wins?

**Default precedence (admin-configurable):**

1. **Most generous benefit wins** when two promos grant the same type of benefit
2. **Non-stacking:** Founding Landowner + Landowner Honeymoon don't stack — user gets 12 months free, not 12 + 3
3. **Stacking allowed:** Veteran discount + Seasonal promo (percentage discounts can compound)
4. **Promo codes override automatic promotions** if the code's benefit is larger
5. **Admin override:** Support staff can apply manual credits that bypass stacking rules

**Tracking:**
- Every applied promo logged in `promotion_claims` table
- User can see their active promotions in account settings
- Admin can audit promo application per user

---

## Free Listing Periods — Specific Landowner Mechanics

Landowner-specific free periods deserve their own treatment because listings generate revenue via transaction fees regardless of whether the landowner pays for subscription.

**Three types of free listing windows:**

### A. Free While On Promo (Ranch features, no charge)
- Founding Landowner, Landowner Honeymoon, seasonal promos
- Full Ranch feature set active
- User sees green "Promo Active" banner in dashboard

### B. Free Listing, No Subscription (Homestead tier)
- Default state for landowners without any promo
- Limited to 1 active listing
- 5% platform fee on bookings
- No time limit — free forever

### C. Paid Subscription Free-Tier Boost Credits
- Ranch subscribers get extra listings/features beyond base
- Estate subscribers get unlimited
- This isn't a "promo" — it's what they pay for

**The combined effect:**
A new landowner can be completely free for the first 90 days (Honeymoon), free forever after that on Homestead (1 listing), or pay to unlock more. **Platform fees generate revenue on every lease signed regardless of landowner subscription tier.**

---

## Messaging & Display

### Where promotions surface in the UI

**Public landing page:**
- Launch banner for Founding Landowner promotion with live claim counter
- Seasonal promo banners (admin-enabled)
- Veteran program callout in pricing section

**Signup flow:**
- Landowner signup: "You qualify for Landowner Honeymoon — 90 days of Ranch tier free"
- Promo code input field at payment step
- Veteran verification offered during signup

**Pricing page:**
- Active promotions appear as badges on affected tiers
- Countdown timers where applicable
- Veteran section highlighted

**User dashboard (after signup):**
- Promotion status card showing active benefits and expiration dates
- Post-promo conversion warnings (30 days before expiration)
- Referral code copy-and-share widget
- Promo code entry field in billing settings

**Admin dashboard:**
- Real-time promotion performance
- Claims vs. limits
- Conversion rate to paid after promo expires
- Cost analysis per promotion

---

## Admin Configuration UI

```
Admin → Promotions → Promotional Periods

    [ + Create New Promotion ]

    ┌───────────────────────────────────────────────────────────────┐
    │ ACTIVE PROMOTIONS                                              │
    ├───────────────────────────────────────────────────────────────┤
    │                                                                │
    │ Founding Landowner — 12mo Ranch Free                          │
    │ Type: Tier Grant   |   Target: New Landowners                 │
    │ Runs: 2025-03-15 → Claim limit reached                        │
    │ Progress: 183 / 500 claimed (37%)                             │
    │ Conversion to paid: 62% (of expired claims)                   │
    │ [ Edit ]  [ Pause ]  [ Duplicate ]  [ End Now ]               │
    │                                                                │
    ├───────────────────────────────────────────────────────────────┤
    │                                                                │
    │ Landowner Honeymoon — 90 Days Ranch                           │
    │ Type: Tier Grant   |   Target: All New Landowners             │
    │ Runs: Permanent (auto-apply on first listing)                 │
    │ Progress: 847 active, 1,204 converted to paid (58%)           │
    │ [ Edit ]  [ Pause ]                                           │
    │                                                                │
    ├───────────────────────────────────────────────────────────────┤
    │                                                                │
    │ Off-Season List-Now-Pay-Later                                 │
    │ Type: Delayed Billing   |   Target: New Landowners            │
    │ Runs: 2026-03-01 → 2026-07-31                                 │
    │ Status: Scheduled                                              │
    │ [ Edit ]  [ Delete ]                                          │
    │                                                                │
    └───────────────────────────────────────────────────────────────┘

    [ + Create New Promotion ]
```

Creating a new promotion opens a step-by-step wizard:

```
Step 1: Type
  ○ Tier Grant (give specific tier features free)
  ○ Percentage Discount
  ○ Dollar Discount
  ○ Free Period (delay first charge)
  ○ Referral Program
  ○ Promo Code Campaign

Step 2: Target
  Account Types: ☑ Hunter ☐ Landowner ☐ Club ☐ Outfitter ...
  Signup Date Range: [From] [To] (optional)
  States: [dropdown multi-select, optional]
  Existing Plan: [dropdown, optional]
  Custom Rules: [JSON editor for advanced rules]

Step 3: Benefit
  Grant Tier: [dropdown of plans]
  Duration: [days/months]
  Post-expiration: ○ Auto-charge ○ Downgrade to free

Step 4: Limits & Controls
  Start Date: [date]
  End Date: [date, optional]
  Max Claims: [number, optional]
  Max Per User: [number, default 1]
  Stackable with other promos: ☑ / ☐
  Requires promo code: ☑ / ☐

Step 5: Messaging
  Display Name: [text]
  Description: [markdown]
  Public landing banner: ☑ / ☐
  Pricing page badge: ☑ / ☐
  Dashboard callout: ☑ / ☐
  Custom CTA text: [text]

Step 6: Review & Launch
  [Preview estimated reach]
  [Launch Immediately] [Schedule] [Save as Draft]
```

---

## Analytics & Reporting

All promotion activity flows into DB 8 Analytics via nightly ETL. Admin reports include:

**Per-promotion metrics:**
- Claims over time
- Conversion rate to paid post-expiration
- Revenue impact (lost subscription revenue vs. driven transaction fees)
- Funnel from signup through first transaction
- Drop-off analysis by stage

**Cohort analysis:**
- Retention comparison: Founding Landowners vs. normal signups
- LTV by acquisition promotion
- Time-to-first-lease by cohort

**ROI calculation:**
- Revenue driven per promotion (transaction fees + eventual subscription)
- Vs. CAC comparison to paid acquisition channels
- Recommendation engine suggests which promos to scale or kill

---

## Integration Points

| System | Integration |
|---|---|
| Stripe | Coupons/discounts auto-created matching promotional_periods |
| Stripe Connect | Platform fees adjusted per landowner promo tier |
| Email (Mailgun) | Drip campaigns for promo expiration warnings |
| Veteran verification (ID.me) | Auto-grants veteran program on success |
| Referral tracking | URL params + signup attribution |
| Analytics (DB 8) | Nightly ETL populates reporting tables |
| Audit log (DB 9) | Every promo claim, expiration, and admin change logged |
