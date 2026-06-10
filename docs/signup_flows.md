# Signup & Onboarding Flows

American Headhunter has **six distinct signup paths**, one per account type. All paths share a common entry point and user record, but diverge in data capture, verification, and activation rules.

---

## Entry Point

Single URL: `americanheadhunter.com/get-started`

```
┌────────────────────────────────────────────────────────┐
│                                                        │
│   Join American Headhunter                            │
│                                                        │
│   I want to...                                        │
│                                                        │
│   ○  Hunt                                             │
│   ○  Lease out my land                                │
│   ○  Join or start a hunting club                     │
│   ○  Offer guided hunts (outfitter)                   │
│   ○  Offer land consulting services                   │
│   ○  Sell gear in the marketplace                     │
│                                                        │
│   [ Continue → ]                                      │
│                                                        │
│   Already have an account? Sign in                    │
│                                                        │
└────────────────────────────────────────────────────────┘
```

The selection determines which flow the user enters. All flows create the same `users` record in DB 1 with `account_type` set accordingly.

---

## Universal Base Steps

Every flow shares these foundation steps before branching:

### Step 1 — Identity
- Email (unique, validated)
- Password (min 12 chars, complexity requirements)
- First name, last name
- Date of birth (determines `age_tier`: adult / junior / coppa)
- Phone number
- Consent: Terms of Service, Privacy Policy, CCPA notice

### Step 2 — Email verification
- Verification email sent
- User clicks link within 24 hours
- Sets `email_verified_at`

### Step 3 — Optional MFA setup
- Offered, not required
- TOTP or SMS-based
- Can be completed later from account settings

After Step 3, the flow branches based on `account_type`.

---

## Flow 1 — Hunter

**Account type:** `hunter`
**Minimum completion for full access:** Steps 1–3 + hunter profile
**Can browse immediately:** Yes (guest browsing also works without signup)

### Hunter-specific steps

**Step 4 — Hunting Profile**
- Primary game species (multi-select from standard species list)
- Preferred hunting states (multi-select)
- Experience level (new / intermediate / experienced / lifetime)
- Group size typically hunted with (solo / 2-4 / 5-8 / 9+)
- Primary weapon types (rifle / bow / shotgun / muzzleloader / crossbow)

**Step 5 — Membership Selection**
- Display active tiers (pulled from DB 12 `membership_plans`)
- Free tier selectable with no payment
- Paid tier requires Stripe payment method
- Veterans option routes to ID.me verification (Module AX)

**Step 6 — Emergency Contact (required)**
- Contact name, phone, relationship
- Stored in `user_profiles.emergency_contact_*` fields
- Required because of SOS safety features

**Step 7 — Optional background check**
- Presented as optional for free tier
- Included benefit on paid tiers (Sportsman gets 1/year, Outfitter gets 3)
- Routes to Checkr verification (Module AB)
- Improves `trust_score` when completed

**Activation:** Immediate. Hunter can browse, apply for leases, save searches. Paid tier features unlock once payment method verified and trial/billing cycle begins.

---

## Flow 2 — Landowner

**Account type:** `landowner`
**Minimum completion for full access:** Steps 1–3 + property setup + W-9
**Can list immediately:** No — must complete property verification

### Landowner-specific steps

**Step 4 — Property Information (first property)**
- Property name
- Physical address (street, city, county, state, zip)
- Parcel ID or legal description (optional but recommended)
- Approximate acreage
- Current ownership (sole owner / LLC / trust / partnership / other)
- Are you authorized to lease this property? (attestation)

**Step 5 — Ownership Verification**
- Upload deed or tax record showing ownership (or attestation for demonstration period)
- For LLC/trust/entity ownership: upload formation documents
- Routes to manual verification queue OR automated parcel API check (where available)
- Landowner can start drafting listing during verification but cannot publish until verified

**Step 6 — Tax Information (W-9)**
- Legal name (individual or business)
- Entity type (sole proprietor / LLC / S-corp / C-corp / partnership / trust)
- TIN (SSN or EIN) — **encrypted** at rest
- Tax address (can differ from property address)
- Signature date
- W-9 PDF generated and stored in DB 11 documents with reference in DB 4 `w9_records`

**Step 7 — Stripe Connect Account Setup**
- Creates Stripe Connect Express account for receiving payouts
- Bank account or debit card for payout destination
- Routes through Stripe's onboarding UI
- Creates record in DB 4 `stripe_connect_accounts`
- Cannot receive payouts until `charges_enabled` and `payouts_enabled` are true

**Step 8 — Membership Selection**
- Display active tiers (pulled from DB 12 `membership_plans`)
- Free Homestead tier selectable with no payment
- Landowner Honeymoon (90-day Ranch tier free) auto-applied to new signups
- Paid tier requires payment method
- Founding Landowner promotion (if active) displayed

**Step 9 — First Listing Setup** (optional at signup, can complete later)
- Upload property photos (required: minimum 3, recommended: 10+)
- Mark approximate property boundaries on map (Mapbox tool)
- Primary game species available
- Amenities (cabin, electricity, water, internet, feeders, stands, food plots)
- Access details (gate code stored encrypted, not shown until lease signed)
- Pricing (per hunter, per season, per acre, flat)
- Lease type (fixed / auction / club / day hunt)

**Activation:** After ownership verification passes. Before verification, landowner can draft but not publish listings. Payouts cannot be processed until Stripe Connect setup is complete.

---

## Flow 3 — Hunting Club

**Account type:** `club_officer` (for whoever creates it) — members join later with `club_member`

### Club-specific steps

**Step 4 — Club Information**
- Club name
- Home state
- Year founded
- Club description / mission
- Current member count (estimate)
- Club logo (optional upload)

**Step 5 — Officer Roles**
- The creating user is auto-assigned as president
- Identify additional officers (can be invited by email)
- Officers: president, secretary, treasurer, hunt master (any combination)

**Step 6 — Bylaws**
- Upload existing bylaws (PDF) OR use the platform's template wizard
- If using template: bylaws wizard collects standard clauses (membership requirements, dues structure, voting rules, guest policies, safety rules)
- Generated bylaws PDF stored in DB 11 documents

**Step 7 — Dues Structure**
- Equal split / weighted by membership class / custom amounts per member
- Billing cycle (annual / semi-annual / quarterly / monthly)
- Collection method (platform handles / club handles externally)

**Step 8 — Membership Selection**
- Basic club features are free
- Premium club tier (pulled from DB 12) unlocks: shared calendar, stand assignment, expense splitting, voting, member announcements, guest pass management
- Premium tier paid by the club treasurer's account

**Step 9 — Invite Members**
- Bulk email invitation tool
- Each invited member receives an email to create their own hunter account
- Once they sign up, they're auto-linked as `club_member` to this club

**Activation:** Club creation is immediate. Members activate individually as they join. Club cannot lease a property until it has at least 3 verified members.

---

## Flow 4 — Outfitter

**Account type:** `outfitter`
**Verification required before listing packages.**

### Outfitter-specific steps

**Step 4 — Business Information**
- Business legal name
- DBA (if different)
- Years in business
- Operating states (which states you're licensed in)
- Insurance carrier name

**Step 5 — Licensing Verification (required)**
- State hunting guide/outfitter license number
- License expiration date
- Upload license photo/PDF
- Verified against state database where API available, manual review otherwise
- Stored in DB 6 `outfitter_profiles`

**Step 6 — Insurance Verification (required)**
- Commercial liability insurance policy number
- Insurance carrier name
- Coverage amount (minimum $1M required)
- Expiration date
- Upload certificate of insurance (COI) PDF
- **Annual renewal reminder** at policy expiration

**Step 7 — Background Check (required)**
- Identity verification via Checkr
- Criminal background check
- Required regardless of membership tier
- Blocks package listing until cleared

**Step 8 — Tax & Payment Setup**
- W-9 entry (same process as landowner)
- Stripe Connect Express setup
- Commission rate shown (pulled from DB 12 — default 10% on bookings)

**Step 9 — Package Listing Setup** (can complete later)
- Upload at least one hunt package to publish profile
- Package details: species, duration, group size, price, inclusions, success rate

**Step 10 — Membership**
- Monthly listing fee (pulled from DB 12 — default $49/mo)
- No free tier for outfitters — they're selling commercial services

**Activation:** After license, insurance, and background check are verified. Can create draft packages before but cannot publish until fully verified.

---

## Flow 5 — Consultant (Land / Habitat)

**Account type:** `consultant`
**Verification required before offering services.**

### Consultant-specific steps

**Step 4 — Professional Background**
- Areas of expertise (wildlife biology / habitat management / food plots / population surveys / timber management / water management / exotic species / other — multi-select)
- Years of experience
- Education/credentials
- Notable projects (optional portfolio URLs)

**Step 5 — Licensing (where applicable)**
- Professional licenses (wildlife biologist, forester, etc. — state-dependent)
- Upload supporting documents

**Step 6 — Insurance**
- Professional liability / E&O insurance (recommended, not required)
- If provided, verification same as outfitter

**Step 7 — Background Check (required)**
- Same Checkr process as outfitter
- Required for working on client properties

**Step 8 — Tax & Payment Setup**
- W-9 entry
- Stripe Connect Express
- Commission rate displayed (default 15% — pulled from DB 12)

**Step 9 — Service Catalog**
- Create services offered: one-time consultations, ongoing management, specific projects
- Pricing: hourly / flat per service / custom quote
- Service area radius

**Step 10 — Free to list**
- No subscription fee for consultants
- Platform takes commission on consulting fees only

**Activation:** After background check clears and at least one service is listed.

---

## Flow 6 — Marketplace Seller

**Account type:** `marketplace_seller`

### Seller-specific steps

**Step 4 — Seller Information**
- Are you: individual seller / small business / licensed FFL dealer
- Location (state, city)
- Selling categories (gear / apparel / firearms / ammunition / archery / taxidermy / vehicles / other)

**Step 5 — Firearms Check (if applicable)**
- If selling firearms: FFL number required, FFL license upload
- Non-firearms sellers skip this step

**Step 6 — Identity Verification**
- Basic identity verification (not full Checkr)
- Reduces fraud

**Step 7 — Tax & Payment Setup**
- W-9 entry
- Stripe Connect Express
- Commission rate (default 8% — pulled from DB 12)

**Step 8 — First Listing** (required to complete signup)
- At least one item listed before profile goes active

**Activation:** Immediate after Stripe Connect setup completes. FFL dealers receive additional review.

---

## Cross-Flow Rules

### Multiple account types
A single user can hold multiple account types. Example: a landowner can also be a hunter (they lease their own land AND hunt on other properties). Handled via role assignments in DB 1 `user_roles`, not by creating duplicate accounts.

When a user adds a secondary account type:
- Core profile stays the same
- Type-specific onboarding runs for just that new type
- New verifications/tax forms collected as needed
- User can switch between modes via account menu

### Minor accounts (under 18)
All flows support minor signups but require:
- Guardian account (existing adult user)
- Guardian consent record in DB 1 `guardian_relationships`
- Parental verification via document upload
- Limited feature access (no payments, no contracts, no SOS subscriptions)

### Veteran path
Every flow offers a "Verify as Veteran" option that routes to ID.me or DD-214 verification (Module AX). On success:
- `users.is_veteran = true`
- `users.veteran_verified_at` set
- Hunter tier auto-upgrades to Veteran tier (free Sportsman equivalent forever)
- Other account types receive configured veteran discount (set in DB 12)

### Abandoned signups
Incomplete signups are:
- Held for 30 days
- Email reminder sent at day 3, day 7, day 21
- Purged at day 30 if still incomplete
- User can resume from email link at any point before purge

---

## Database Touchpoints During Signup

| Step | Database | Tables |
|---|---|---|
| Step 1-2 (Identity + Email) | DB 1 Identity | `users`, `user_profiles`, `consent_log` |
| Step 3 (MFA) | DB 1 Identity | `user_mfa`, `mfa_backup_codes` |
| Hunter profile | DB 1 Identity | `user_profiles` (custom_fields) |
| Property setup | DB 2 Property | `properties`, `property_photos`, `property_pricing` |
| Ownership verification | DB 11 Documents, DB 9 Audit | `documents`, `audit_log` |
| W-9 | DB 4 Billing | `w9_records` |
| Stripe Connect | DB 4 Billing | `stripe_connect_accounts` |
| Club creation | DB 3 Lease | `clubs`, `club_members`, `club_governance` |
| Outfitter profile | DB 6 Commerce | `outfitter_profiles` |
| Consultant setup | DB 6 Commerce | (new: `consultant_profiles`) |
| Seller setup | DB 6 Commerce | `marketplace_listings` |
| Membership | DB 4 Billing, DB 12 Platform | `subscriptions`, `membership_plans` |
| All verifications | DB 1 Identity | `identity_verifications`, `veteran_verifications` |

Every signup step writes to DB 9 `audit_log`.
