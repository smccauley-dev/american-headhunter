# American Headhunter — Full Platform Scope

## Document Purpose
This document defines the complete product scope for a full-stack hunting lease and land management SaaS platform. It is intended to be used as the primary reference for architecture, data modeling, and development planning. Every module, feature, and integration listed here has been deliberately scoped and approved.

---

## Platform Identity

| Attribute | Value |
|---|---|
| **Type** | Multi-portal SaaS Web Platform + PWA Mobile |
| **Primary Stack** | Laravel 11 · PostgreSQL 16 · Valkey · Mapbox · Stripe |
| **Target Market** | Landowners, Hunting Lessees, Land Managers — US Market (Canada Phase 2) |
| **Architecture** | API-first, multi-tenant capable, offline-first PWA |

---

## Full Technology Stack

| Layer | Technology |
|---|---|
| **Framework** | Laravel 11 |
| **Admin CMS** | Filament 3 |
| **Frontend** | Inertia.js + React |
| **Database** | PostgreSQL 16 + PostGIS + pgcrypto |
| **Cache / Queue / Sessions** | Valkey |
| **Real-time** | Laravel Echo + Reverb (WebSockets) |
| **Maps** | Mapbox GL JS + OnX parcel data + USGS/USFWS overlays |
| **Payments** | Stripe + Laravel Cashier + Stripe Connect |
| **ACH Payments** | Stripe ACH + Plaid instant bank verification |
| **BNPL** | Affirm via Stripe |
| **e-Signature** | Dropbox Sign API |
| **Background Checks** | Checkr API |
| **Identity Verification** | ID.me (veteran/military), OFAC/AML screening service |
| **Sales Tax** | TaxJar or Avalara |
| **1099 Filing** | Tax1099 API |
| **Accounting** | QuickBooks Online + Xero APIs |
| **File Storage** | Azure Blob Storage (hot/cool/archive tiering) |
| **File Security** | ClamAV or VirusTotal API (virus scanning) |
| **Video Processing** | FFmpeg + HLS transcoding pipeline |
| **Video Delivery** | Cloudflare Stream or Azure CDN |
| **Search** | Laravel Scout + Meilisearch |
| **Email** | Laravel Mail + Postmark |
| **SMS** | Twilio |
| **Push Notifications** | PWA Web Push + Laravel Echo |
| **Live Chat** | Intercom or Crisp |
| **Weather** | Tomorrow.io API + NOAA NWS Alerts API |
| **Trail Cameras** | CuddeLink + Spartan + Stealth Cam APIs |
| **Smart Locks** | LockState + Schlage Connect APIs |
| **Insurance** | Great American + Outdoor Underwriters APIs |
| **Marketing** | Klaviyo + GA4 + Meta Pixel + GTM |
| **Cookie Consent** | OneTrust or CookieYes |
| **Error Tracking** | Sentry |
| **APM** | Datadog or New Relic |
| **Logging** | Papertrail or Loggly |
| **Uptime Monitoring** | Better Uptime + PagerDuty |
| **CDN / WAF** | Cloudflare |
| **Feature Flags** | LaunchDarkly or built-in |
| **SSO / SAML** | Laravel + SAML2 package + Socialite (OAuth) |
| **Accessibility Testing** | axe-core in CI/CD pipeline |
| **CI/CD** | GitHub Actions |
| **Hosting** | Azure App Service |
| **Secrets Management** | Azure Key Vault |
| **AI / ML (Phase 30)** | Python microservice — PyTorch / scikit-learn |

---

## The 5 Core Portals

### Portal 1: Public Frontend
**Purpose:** Discovery, SEO, lead generation, brand presence

| Feature | Details |
|---|---|
| Property listings | Photo galleries, acreage, state, county, game types, amenities |
| Map search | Mapbox GL — radius search, boundary overlays, satellite view |
| Advanced filters | Species, price range, acreage, lease type, amenities, availability |
| Property detail pages | Full info, photo carousel, map, availability calendar, inquiry CTA |
| Availability calendar | Per-property public availability display |
| Inquiry / application form | Lead capture — feeds Customer Portal pipeline |
| SEO architecture | Clean URLs, meta tags, sitemap, structured data |
| Static pages | About, How It Works, FAQ, Contact, Blog |
| Mobile responsive | PWA-ready, installable on mobile |
| Property comparison | Select 2–4 properties for side-by-side comparison |
| Saved searches | Save filter criteria, receive alerts on matches |
| Social sharing | Facebook, Instagram, WhatsApp, X on all listing pages |
| Virtual tour | Video, 360° photos, scheduled video call walkthroughs |
| Fishing access indicator | Clear designation of fishing rights included/excluded |
| Exotic game flag | High-fence / exotic species property designation |
| Conservation overlays | FEMA flood, wetlands, endangered species, soil survey |
| Cellular coverage indicator | Known dead zones displayed per property |
| Carbon credit potential | Estimated sequestration value displayed for eligible properties |

---

### Portal 2: Customer Portal (Prospective Lessees)
**Purpose:** Application pipeline — from inquiry to signed lessee

| Feature | Details |
|---|---|
| Account registration | Email + password, email verification, social login (Google/Apple/Facebook) |
| Magic link authentication | Passwordless email login option |
| Profile management | Personal info, emergency contact, hunting licenses |
| Hunter trust score | Visible reputation score — verification, history, payment reliability |
| Hunter verification | Background check (Checkr), license upload, expiry tracking |
| Liability insurance | Upload proof, expiry tracking and alerts |
| Lease applications | Apply for properties, track application status |
| Lease negotiation | Counter-offer workflow, structured term negotiation thread |
| Document upload | ID, hunting license, insurance, waivers, certifications |
| e-Signature workflow | Review and sign lease agreements digitally — ESIGN/UETA compliant |
| Application fee payment | Stripe — one-time fees, ACH option for large amounts |
| Messaging | Thread-based communication with admin/landowner |
| Notifications | Email + in-app — application status, document requests, approvals |
| Club discovery | Browse and apply to hunting clubs seeking members |
| Minor account | Guardian-linked account for hunters under 18 — co-signature workflow |
| COPPA account | Restricted account type for hunters under 13 |
| Veteran/military status | ID.me verification — unlocks veteran pricing tier |
| Promo code redemption | Apply discount codes at checkout |
| Saved searches | Save property search criteria, receive new listing alerts |
| Comparison tool | Compare properties before applying |
| Waitlist enrollment | Join waitlist for fully leased properties |
| Certification tracking | Hunter ed, safety courses, ATV cert, first aid — upload and track expiry |
| Data privacy controls | CCPA rights — view data, request deletion, export data |

---

### Portal 3: Member Portal (Active Lessees)
**Purpose:** Everything an active paying lessee needs day-to-day

#### Lease & Account
| Feature | Details |
|---|---|
| Dashboard | Active leases, expiry countdown, payment status, alerts |
| Lease documents | View/download signed agreements, addenda, rules |
| Payment history | All transactions, receipts, upcoming billing |
| Renewal workflow | Initiate renewal, review updated terms, re-sign |
| Secure property info | Gate codes, access instructions (encrypted, lease-active only) |
| Security deposit status | View deposit held, return status |
| Early termination | Formal request workflow, fee calculation |

#### Scheduling & Access
| Feature | Details |
|---|---|
| Hunt scheduling | Book specific dates/stands to avoid overcrowding |
| Stand/blind selection | Map-based — select assigned or available stand per visit |
| Guest pass management | Add guests per visit, within lease-configured allotment |
| Conflict detection | Prevent double-booking same stand same day |
| Blackout date visibility | See restricted dates set by admin/landowner |
| Agricultural conflict calendar | See crop schedules, livestock movements, timber operations |
| Camp management | Register, manage, and document camp structures |
| QR code check-in | Scan property QR at entrance — validates and logs check-in |

#### Wildlife & Field Management
| Feature | Details |
|---|---|
| Harvest logging | Species, date, time, GPS, weapon type, photos, notes — offline capable |
| Fishing harvest logging | Species, weight, length, GPS, catch-and-release vs. keep |
| Exotic harvest logging | Exotic species, trophy fee trigger on submission |
| Game sighting log | Trail camera uploads, sighting entries, GPS pins |
| Weather widget | Forecast + solunar tables per property location |
| NOAA emergency alerts | Severe weather, tornado, flood, wildfire push alerts |
| Quota tracker | Per-species harvest limits, remaining quota display |
| Stand registry | View all stands/blinds/feeders on leased property |
| Field notes | Free-form notes per visit — offline capable |
| Trophy scoring | Boone & Crockett, SCI score entry on harvest log |
| Cellular coverage map | Dead zone overlay before heading into field |

#### Communication
| Feature | Details |
|---|---|
| In-app messaging | Thread with admin/landowner |
| Club feed | Internal club communication (if club member) |
| Broadcast notifications | Receive property-wide announcements |
| Push notifications | Payment due, lease expiry, quota alerts, broadcasts |
| Emergency alerts | Urgent property notifications — weather, access, safety |
| SOS button | One-tap emergency alert with GPS broadcast |

---

### Portal 4: Admin / System Management Backend
**Purpose:** Full operational control of the entire platform

#### Property Management
| Feature | Details |
|---|---|
| Property CRUD | Create/edit/archive listings with all fields |
| Dynamic field builder | Custom fields per property type — JSONB backed |
| Photo/media management | Upload, reorder, tag property photos |
| Video management | Upload, transcode, publish property walkthrough videos |
| Map boundary editor | Draw/upload parcel polygons in Mapbox |
| Infrastructure registry | Stands, blinds, feeders, cabins, roads, water sources, camps |
| Water body registry | Ponds, creeks, rivers, tanks — species present, stocking records |
| Availability management | Set open/closed dates, blackout periods |
| Pricing configuration | Per-hunter, per-acre, seasonal, custom pricing models |
| Fishing rights configuration | Included, excluded, or separate lease designation |
| Mineral/timber rights | Surface, mineral, timber rights designations per property |
| Exotic game configuration | High-fence designation, exotic species list, trophy fees |
| Conservation overlays | FEMA, wetlands, endangered species layers — admin managed |
| Carbon credit data | Sequestration estimate, program eligibility, broker connections |
| Agricultural calendar | Crop schedules, livestock zones, timber operations |
| Cellular coverage notes | Admin logs known dead zones per property |
| QR code generation | Generate and print property, stand, and equipment QR codes |
| Print materials | Welcome packets, posting signs, emergency cards |

#### Lease Management
| Feature | Details |
|---|---|
| Full lease lifecycle | Application → Negotiation → Approval → Signing → Active → Renewal → Terminated/Expired |
| Dynamic lease terms builder | Custom fields/clauses per lease type — JSONB backed |
| State-specific templates | Lease template variants per state — attorney reviewed |
| Template versioning | Full history of template changes |
| Addendum workflow | Mid-lease changes — generate, notify, re-sign |
| Bulk renewal processing | Mass renewal initiation for end-of-season workflows |
| Expiry monitoring | Dashboard of upcoming expirations — 30/60/90 day queues |
| Security deposit management | Escrow holds, condition reports, return/dispute workflow |
| Early termination management | Both-party initiated, force majeure, refund calculation |

#### User Management
| Feature | Details |
|---|---|
| All user CRUD | Customers, members, clubs, landowners, staff, admins |
| Role & permission system | Granular RBAC — per portal, per feature |
| Hunter trust score admin | Review, override, investigate trust scores |
| Hunter verification review | Approve/reject background checks, license uploads |
| Certification compliance | Platform-wide certification status overview |
| Minor/guardian management | Link minor accounts to guardians, manage co-signature requirements |
| Veteran verification | Review ID.me verifications, apply veteran pricing |
| Impersonation | Staff log in as any user for support — audit-logged, time-limited |
| Banned user management | Permanent ban, suspension, shadow ban, device fingerprinting |
| OFAC/AML review | Flagged user review queue — sanctions and suspicious activity |
| CCPA/privacy requests | Right to deletion, data export requests — workflow and audit |

#### Landowner Management
| Feature | Details |
|---|---|
| Landowner accounts | Separate access tier for property owners |
| Entity ownership | LLC, trust, partnership, corporation as owner |
| Succession management | Designate successors, activate succession workflow |
| Revenue splits | Platform fee vs. landowner payout percentage |
| Stripe Connect payouts | Automated disbursements to landowners |
| Landowner reporting | Revenue, lessees, renewals per property |
| Expense tracking | Property expenses for P&L and tax prep |
| Capital improvement log | Track improvements for estate valuation |
| 1099 generation | Auto-generate for landowners earning through platform |

#### Platform Operations
| Feature | Details |
|---|---|
| Broadcast messaging | Property-wide or platform-wide announcements |
| Notification template manager | Edit all system email/push templates |
| Automation workflow builder | Visual if/then automation rules — no-code |
| Audit log | Immutable record of every data mutation — who, what, when |
| System configuration | Fees, rates, feature flags, API keys |
| Content management | FAQ, static pages, blog |
| Feature flag management | LaunchDarkly — per-user, per-tenant, percentage rollout |
| Maintenance mode | Portal-specific maintenance with custom messaging |
| Error tracking dashboard | Sentry — real-time errors, affected users |
| APM dashboard | Datadog — query performance, resource utilization |

---

### Portal 5: Reporting Suite
**Purpose:** Business intelligence across all platform stakeholders

| Report | Audience | Details |
|---|---|---|
| Revenue by property | Admin/Landowner | MRR, ARR, per-acre revenue |
| Lease pipeline | Admin | Applications → Conversions → Active |
| Renewal rate | Admin | Renewal % by property, season, region |
| Expiring leases | Admin | 30/60/90 day expiry queue |
| Payment delinquency | Admin | Overdue accounts, retry status |
| Harvest summary | Admin/Landowner/Member | Species, volume, by property/season |
| Fishing harvest summary | Admin/Landowner/Member | Species, weight, location, season |
| Occupancy rate | Admin/Landowner | Days hunted vs. available |
| Hunter retention | Admin | Return rate year-over-year |
| Quota utilization | Admin/Landowner | Per-species remaining vs. harvested |
| Club health | Admin | Dues compliance, member count, dispute rate |
| Platform KPI dashboard | Admin | MRR, ARR, churn, CAC, LTV, GMV |
| Cohort analysis | Admin | Retention by acquisition cohort |
| Conversion funnel | Admin | Listing view → lease signed — drop-off per stage |
| NPS / CSAT | Admin | Automated survey results and trends |
| Auction performance | Admin | Bids per listing, final vs. reserve |
| Consulting revenue | Admin | By service type, consultant, region |
| Outfitter performance | Admin | Bookings, revenue, cancellation rate, ratings |
| Marketplace GMV | Admin | Transaction volume, take rate, top categories |
| Advertising revenue | Admin | By placement, advertiser, campaign |
| Export formats | All | PDF, CSV, Excel |
| Scheduled reports | Admin/Landowner | Monthly email summaries |

---

## Module A: Auction-Based Lease Bidding

**Purpose:** Allow landowners to auction hunting leases to the highest bidder — maximizing lease value and creating competitive urgency.

### Auction Listing Setup
| Feature | Details |
|---|---|
| Auction creation | All standard property fields + auction-specific configuration |
| Reserve price | Minimum acceptable bid — hidden or visible (configurable) |
| Starting bid | Opening bid floor |
| Bid increment | Minimum raise per bid (e.g. $50, $100, $250) |
| Auction window | Start date/time, end date/time |
| Auto-extend | Bid in final X minutes extends auction by Y minutes — prevents sniping |
| Buy It Now price | Optional — instant win at set price, closes auction immediately |
| Lease type | Annual, seasonal, multi-year — configured upfront |
| Hunter limit | Single lessee or group/club lease with max hunters |
| Auction visibility | Public or invite-only to verified hunters |

### Bidding Experience
| Feature | Details |
|---|---|
| Live bid display | Current high bid, bidder count, time remaining — real-time via WebSocket |
| Bid placement | Enter amount — must meet increment minimum |
| Auto-bid (proxy bidding) | Set maximum — system auto-bids up to that amount |
| Bid history | Full transparent bid log with timestamps |
| Watchlist | Save auctions to watch without bidding |
| Outbid notifications | Instant email + push when surpassed |
| Countdown timer | Live clock — auto-extend indicator |
| Payment hold | Card pre-authorized at bid time — charged only on win |
| Bid retraction | Admin-controlled policy — limited retractions |

### Auction Close & Fulfillment
| Feature | Details |
|---|---|
| Winner notification | Immediate email + in-app with next steps |
| Loser notifications | Notification + waitlist offer |
| Winner flow | Automatically enters standard lease signing + payment flow |
| Payment capture | Pre-authorized hold captured on win |
| No-pay protection | Winner has X hours to complete — forfeits hold on non-payment |
| Reserve not met | Admin notified — option to accept best offer or relist |
| Auction waitlist | Runner-up bidders offered position if winner withdraws |

### Admin Auction Management
| Feature | Details |
|---|---|
| Auction dashboard | All active, upcoming, and closed auctions |
| Bid monitoring | Real-time bid activity per auction |
| Manual close/extend | Admin can close early or extend |
| Dispute management | Bid dispute resolution workflow |
| Shill bidding detection | Flag suspicious bidding patterns |
| Auction reserve advisor | Suggest reserve based on comparable fixed-price leases |

---

## Module B: Habitat & Wildlife Management Consulting Services

**Purpose:** Connect landowners with wildlife biologists and land improvement specialists — premium upsell with escrow-backed payments.

### Two Sub-Models
- **Internal Consulting** — platform employs/contracts consultants
- **Consultant Marketplace** — third-party consultants create profiles, platform takes service fee

### Consultant Profiles
| Feature | Details |
|---|---|
| Profile | Bio, credentials, certifications, specialties, service area |
| Specialty tags | Deer management, waterfowl, food plots, prescribed burns, timber, soil, predator control, CWD |
| Service area map | States/counties — Mapbox overlay |
| Portfolio | Past projects, before/after photos, testimonials |
| Credentials verification | Admin reviews and badges verified consultants |
| Availability calendar | Consultant sets available dates per service type |
| Ratings & reviews | Post-service landowner reviews |

### Service Catalog
| Service Type | Details |
|---|---|
| Wildlife management plan (WMP) | Full written plan — deliverable document |
| Habitat assessment | On-site visit + written assessment |
| Food plot design | Layout, species selection, soil recommendations |
| Prescribed burn planning | Regulatory-compliant burn plan |
| Herd population survey | Census methodology, reporting |
| Trophy deer management | Selective harvest strategy, genetics planning |
| Predator control | Coyote/hog management plans |
| Timber & brush management | Selective clearing, bedding area creation |
| Water source development | Pond, spring, waterhole planning |
| Custom consultation | Open-scope hourly or day-rate engagement |

### Booking & Delivery Workflow
1. Landowner browses consultants/services
2. Selects service + preferred dates, submits request with property details
3. Consultant accepts or proposes alternatives
4. Landowner confirms — payment held in escrow (Stripe)
5. Service delivered (on-site or remote)
6. Deliverable uploaded to platform
7. Landowner reviews and approves deliverable
8. Payment released to consultant
9. Review submitted

### Integration with Core Platform
| Integration | Details |
|---|---|
| WMP attached to property | Consulting deliverables stored against property record |
| Harvest data feeds consulting | Consultant can view property harvest logs for analysis |
| Lessee-visible plans | Landowner can share WMP with active lessees |
| Quota recommendations | Consultant recommendations feed into quota management |
| MLDP documentation | WMP supports TPWD MLDP program application |

---

## Module C: Outfitter & Guide Booking

**Purpose:** Hunters book guided hunts with licensed outfitters — separate product line with distinct portal.

### Outfitter Dashboard
| Feature | Details |
|---|---|
| Outfitter profile | Business info, licenses, insurance, bio, photos |
| License verification | State outfitter/guide license — admin verified |
| Package builder | Species, duration, group size, price, inclusions |
| Availability calendar | Block dates, set trip capacity |
| Trip roster | All booked hunters per trip |
| Client messaging | Per-booking communication thread |
| Revenue dashboard | Bookings, revenue, upcoming trips |
| Ratings & reviews | Post-trip client reviews |

### Hunt Package Configuration
| Feature | Details |
|---|---|
| Species | Whitetail, turkey, hog, waterfowl, predator, dove, exotics |
| Duration | Half-day, full-day, 2-day, 3-day, week-long |
| Group size | Min/max hunters per trip |
| Inclusions | Lodging, meals, field dressing, transportation, equipment |
| Experience level | Beginner, intermediate, experienced |
| Success rate | Outfitter-reported historical success rates |
| Add-ons | Trophy processing, taxidermy referral, extra guide days |
| Property linking | Tie packages to platform listings or external land |

### Hunter Booking Experience
| Feature | Details |
|---|---|
| Browse packages | Filter by species, state, price, duration, rating |
| Date selection | Pick available trip dates |
| Group booking | Book for multiple hunters |
| Deposit + balance | Stripe — configurable deposit at booking, balance before trip |
| Booking management | Upcoming trips, documents, outfitter contact |
| Pre-trip checklist | What to bring, licensing requirements, travel info |
| Post-trip review | Rate outfitter and experience |
| Harvest log link | Auto-link to member harvest log if platform member |
| Gift a guided hunt | Purchase as gift — redeemable voucher |
| Trip cancellation insurance | Offered at checkout |

---

## Module D: Marketplace — Hunting Equipment & Land Services

**Purpose:** Curated marketplace for equipment, gear, and land services — P2P and vendor listings.

### Product Categories
- Trail cameras (game cams, cellular cams, mounts)
- Feeders & attractants (gravity feeders, protein feeders, corn, minerals)
- Deer stands & blinds (ladder stands, box blinds, hang-ons)
- Food plot supplies (seed, fertilizer, soil amendments)
- Access & security (gate locks, signs, fence supplies)
- Hunting gear (firearms accessories, archery, clothing, optics)
- Land vehicles (ATVs, UTVs, implements)
- Processing equipment (meat grinders, coolers, game bags)
- Safety equipment (first aid, communication devices)

### Service Categories
- Fencing & access installation
- Habitat work (brush clearing, dozer, road grading)
- Food plot services (discing, planting, fertilizing)
- Taxidermy
- Wildlife surveys
- Land surveying
- Legal services (lease review, hunting law consultation)

### Seller Portal
| Feature | Details |
|---|---|
| Seller registration | Individual or business account |
| Seller verification | Identity check — business license for vendors |
| Listing creation | Title, description, photos, price, condition, location/shipping |
| Dynamic listing fields | Category-specific custom fields |
| Inventory management | Stock quantity, sold tracking, restock alerts |
| Order management | View orders, mark shipped, upload tracking |
| Seller dashboard | Sales, revenue, ratings, pending orders |
| Stripe Connect payout | Net of platform commission |
| Sponsored listings | Featured placement option |

### Buyer Experience
| Feature | Details |
|---|---|
| Browse & search | Filter by category, price, condition, location, rating |
| Make offer | Negotiation option on P2P listings |
| Cart & checkout | Stripe — standard e-commerce |
| Shipping or local pickup | Seller configures fulfillment |
| Buyer protection | Dispute resolution, return policy |
| Reviews | Post-purchase ratings |
| Saved searches | Alert on matching new listings |
| Wishlist | Save items for later |

---

## Module E: Hunting Club / Group Lease Management

**Purpose:** Model the most common real-world leasing structure — clubs of multiple hunters sharing a single lease.

### Club Entity Model
| Feature | Details |
|---|---|
| Club registration | Named club entity — separate from individual accounts |
| Club officer roles | President, Secretary, Treasurer, Hunt Master — distinct permissions |
| Club size limits | Min/max member count — enforced per lease terms |
| Club profile | Name, logo, description, home state, founding year |
| Club verification | Officer identity verified before lease eligibility |
| Multi-club membership | One user can belong to multiple clubs |
| Club transfer | Ownership/officer role transfer workflow |

### Club Membership Management
| Feature | Details |
|---|---|
| Member invitations | Officer invites via email — tracked, expiring links |
| Member approval | Officer approves/rejects membership requests |
| Member removal | Officer-initiated — triggers lease access revocation |
| Membership waitlist | Per-club waitlist when at capacity |
| Member roles | Full, associate, youth, guest member |
| Individual compliance | Each member completes verification, license, waiver |
| Member status | Active, pending verification, suspended, removed |

### Club Lease Dynamics
| Feature | Details |
|---|---|
| Club as lessee | Club entity signs the lease — not individuals |
| Officer signing authority | Designated officer signs on behalf of club |
| Member sub-agreements | Each member signs individual rules acknowledgment and waiver |
| Lease cost splitting | Equal or weighted split — configurable |
| Dues invoicing | Individual member invoices from club total |
| Dues payment tracking | Per-member status visible to officers |
| Member non-payment | Auto-flag to officer — configurable suspension trigger |

### Club Governance
| Feature | Details |
|---|---|
| Club rules document | Officers post club-specific rules |
| Voting system | Members vote on rule changes, new admissions, major decisions |
| Meeting notes | Officers post summaries to club feed |
| Officer elections | Formal role transfer via member vote |
| Bylaw document storage | Upload and version club bylaws |

### Club Operations
| Feature | Details |
|---|---|
| Stand assignment | Officers assign stands to members for the season |
| Guest allotment | Each member has their own guest pass allowance |
| Shared harvest logs | All member harvests contribute to property record |
| Club communication | Internal club feed — separate from landowner communication |
| Club calendar | Shared hunt scheduling, events, work days |
| Work day tracking | Members log volunteer habitat work hours |
| Club treasury | Basic income/expense ledger for dues and shared expenses |

---

## Module F: Legal & Compliance Architecture

**Purpose:** Ensure every lease, signature, and document is legally defensible, state-compliant, and properly executed.

### State-Specific Legal Framework
| Feature | Details |
|---|---|
| State template library | Lease template variants per state — all 50 states |
| State-specific clauses | Mineral rights, water rights, trespass liability, surface damage, hunting easements |
| Auto-template selection | Correct template selected based on property state |
| Attorney-reviewed badges | Templates reviewed by hunting law attorneys — badged and dated |
| Regulatory update workflow | Affected templates flagged when state law changes |
| Multi-state properties | Properties spanning state lines — blended template logic |

### Minor / Guardian Workflow
| Feature | Details |
|---|---|
| Age gate on registration | Date of birth collected — under-18 flagged automatically |
| Guardian co-registration | Minor accounts linked to verified adult guardian |
| Guardian co-signature | All leases and waivers require guardian e-signature |
| Parental consent document | Separate consent form per minor per lease |
| Youth hunter designation | Special member type — distinct permissions and quotas |
| Age-out workflow | Automatic transition to full adult account at 18 |
| COPPA compliance | Under-13 — strict federal compliance, no behavioral data |

### e-Signature Legal Compliance
| Feature | Details |
|---|---|
| ESIGN Act compliance | Intent to sign captured, disclosure accepted, copy provided |
| UETA compliance | Full audit trail per execution |
| Signature audit trail | IP address, timestamp, device, geolocation logged |
| Identity verification at signing | SMS OTP or email confirmation |
| Tamper-evident documents | Signed PDFs cryptographically sealed |
| Signature certificate | Dropbox Sign certificate stored with every executed document |
| Long-term storage | Executed documents retained minimum 7 years |

### Lease Addendum Workflow
| Feature | Details |
|---|---|
| Addendum creation | Admin/landowner drafts formal addendum |
| Addendum notification | All parties notified with change summary |
| Re-signature requirement | Configurable — material changes always require re-sign |
| Addendum versioning | Full history — numbered sequentially |
| Addendum audit trail | Created, approved, signed — by whom, when |

### Attorney Review Workflow
| Feature | Details |
|---|---|
| Attorney review request | Landowner flags lease for attorney review |
| Attorney partner network | Hunting law attorneys — directory per state |
| Review assignment | Routed to available attorney in property state |
| Attorney annotation | Attorney marks up template with comments |
| Review turnaround SLA | 24–72 hour commitment |
| Review fee | Billed through platform — Stripe Connect to attorney |

---

## Module G: Incident & Emergency Management

**Purpose:** Protect hunters, landowners, and the platform through structured incident reporting and emergency response.

### Hunt Check-In / Check-Out
| Feature | Details |
|---|---|
| Check-in system | Member logs hunt start — timestamp, GPS, stand selected |
| Check-out system | Member logs hunt end — all-clear confirmation |
| Overdue alert | No check-out after X hours — alert emergency contact and admin |
| Check-in history | Full log per member per property |
| Active hunter display | Officers and admin see who is checked in |
| Buddy system | Designate hunt partner who receives check-in notifications |
| QR code check-in | Scan stand or property entrance QR |

### Emergency SOS
| Feature | Details |
|---|---|
| SOS button | PWA — one-tap emergency alert — works offline via SMS |
| GPS broadcast | Precise coordinates to emergency contacts + admin |
| Emergency contact cascade | Primary → secondary → admin if no acknowledgment |
| 911 guidance | Nearest emergency services + property coordinates |
| SOS log | Every SOS event permanently recorded |

### Incident Reporting
| Feature | Details |
|---|---|
| Incident types | Hunting accident, trespassing, poaching, property damage, fire, medical, vehicle, theft |
| Structured report form | Type-specific fields |
| GPS location pin | Exact incident location on property map |
| Photo/video attachment | Evidence documentation |
| Witness capture | Other party details, witness info |
| Timestamp lock | Auto-populated — cannot be altered after submission |
| Incident status | Reported, Under Review, Resolved, Referred to Authorities |
| Admin incident dashboard | All incidents — filterable, exportable |

### Poaching & Trespass Response
| Feature | Details |
|---|---|
| Game warden directory | Per-county contacts auto-populated by property location |
| Poaching report template | Pre-formatted to match state game warden requirements |
| One-click warden contact | Call or email directly from incident report |
| Evidence package export | PDF incident report with photos for law enforcement |
| Repeat offender log | Track recurring trespass incidents |

### Insurance Integration
| Feature | Details |
|---|---|
| Hunting lease insurance partners | Pre-integrated providers |
| Policy storage | Upload and track per user per property |
| Incident-to-claim workflow | Incident report converts to claim initiation |
| Policy expiry tracking | Alert before coverage lapses |
| Minimum coverage enforcement | Required coverage before lease activation |

---

## Module H: Financial & Tax Compliance

**Purpose:** Full IRS and state tax obligation compliance.

### 1099 Generation
| Feature | Details |
|---|---|
| 1099-NEC | Consultants and outfitters earning $600+ annually |
| 1099-K | Marketplace sellers exceeding IRS threshold |
| W-9 collection | Required before first payout |
| TIN verification | IRS TIN matching API |
| Year-end processing | Batch generation every January |
| E-filing | Direct IRS e-file via Tax1099 API |
| Recipient delivery | Digital + mailed paper copy option |
| Correction workflow | Amend and refile incorrect 1099s |
| Archive | 7-year retention per IRS requirement |

### Sales Tax
| Feature | Details |
|---|---|
| TaxJar / Avalara | Automated calculation on marketplace transactions |
| Economic nexus tracking | Monitor thresholds per state |
| Tax collection | Applied at checkout for physical goods |
| Tax remittance | Automated monthly/quarterly |
| Exemption certificates | Accept and store |
| Tax reporting | By state — exportable for CPA |

### Revenue Recognition
| Feature | Details |
|---|---|
| Deferred revenue tracking | Annual/multi-year leases recognized monthly |
| Revenue recognition schedule | Auto-generated per lease — GAAP compliant |
| Prepaid lease handling | Lump-sum payments split across recognition periods |
| Refund impact | Recognition adjusted on refund |

### Accounting Integrations
| Feature | Details |
|---|---|
| QuickBooks Online | Bi-directional sync |
| Xero | Same scope as QuickBooks |
| Chart of accounts mapping | Admin configures GL account mapping |
| Reconciliation report | Monthly transactions vs. Stripe payouts export |

### Landowner Financial Tools
| Feature | Details |
|---|---|
| Lease income tracking | All payments per property per year |
| Property expense logging | Feeder costs, seed, fencing, maintenance |
| Income vs. expense P&L | Per property per tax year — exportable PDF |
| Schedule F / E prep | Export for tax preparer |
| Capital improvement tracking | For depreciation and estate valuation |

---

## Module I: Regulatory & Wildlife Compliance

### State Season Calendar
| Feature | Details |
|---|---|
| Season database | All 50 states — species, weapon type, open/close, bag limits |
| Annual update workflow | Admin updates when states publish new regs |
| Property-specific display | Members see legal seasons for their property |
| Multi-species calendar | Deer, turkey, hog, waterfowl, predator, dove, exotics |
| Weapon season breakdown | Archery, rifle, muzzleloader, crossbow |
| Season change notifications | Push/email to affected members |
| Bag limit enforcement | Hard stops in harvest logging if configured |

### CWD Compliance
| Feature | Details |
|---|---|
| CWD zone mapping | Management zones per state on property maps |
| CWD acknowledgment | Required before harvest logging in affected zones |
| Carcass movement rules | State-specific restrictions displayed |
| CWD harvest flagging | Harvests flagged — member prompted for sample |
| State reporting export | CWD harvest report in state-required format |
| Positive test workflow | Notification chain to member, landowner, admin |

### MLDP Program Support (Texas)
| Feature | Details |
|---|---|
| MLDP application support | Documentation for TPWD MLDP application |
| WMP linkage | WMP linked to MLDP application |
| Population survey tools | Standardized forms matching TPWD requirements |
| Harvest reporting format | Export logs in TPWD-required format |
| Permit tracking | MLDP permits, species covered, expiry |
| Extended season display | MLDP properties show extended season dates |

### Game Warden Directory
| Feature | Details |
|---|---|
| County-level contacts | Name, phone, email per county — all 50 states |
| Auto-populated per property | Property location auto-links to correct warden |
| Annual verification | Directory reviewed and updated annually |
| Direct contact integration | One-click from incident and poaching reports |

---

## Module J: Marketing & Growth Tools

### Referral Program
| Feature | Details |
|---|---|
| Landowner referral | Earns credit or fee reduction |
| Hunter referral | Earns subscription credit |
| Unique referral links | Per-user tracked links |
| Referral dashboard | Clicks, signups, conversions, rewards |
| Reward fulfillment | Auto-applied credits |
| Referral tiers | Escalating rewards |

### Affiliate Program
| Feature | Details |
|---|---|
| Affiliate registration | Bloggers, podcasters, influencers |
| Affiliate approval | Admin review |
| Unique tracking links | Per-affiliate tracked URLs |
| Commission structure | Percentage of first lease or flat fee |
| Affiliate dashboard | Traffic, conversions, commissions |
| Commission payouts | Stripe Connect — monthly |
| Creative assets | Pre-built banners, copy, social assets |

### SEO Infrastructure
| Feature | Details |
|---|---|
| Auto-generated state pages | One per state — auto-populated from listings |
| Auto-generated county pages | Hyper-local SEO |
| Auto-generated species pages | Intent-matched landing pages |
| Structured data / schema | ListingSchema, LocalBusiness, Review |
| XML sitemap | Auto-generated, auto-updated |
| Meta tag management | Admin-configurable per key page |
| Blog / content CMS | Article publishing |

### Marketing Integrations
| Feature | Details |
|---|---|
| Klaviyo | User segments — behavioral drip campaigns |
| Google Analytics 4 | Full funnel tracking |
| Meta Pixel | Facebook/Instagram conversion tracking |
| Google Tag Manager | Container-based tag management |
| UTM parameter tracking | End-to-end attribution |

---

## Module K: Customer Support Infrastructure

| Feature | Details |
|---|---|
| Ticketing system | Built-in or Zendesk/Freshdesk integration |
| Priority routing | Critical tickets auto-escalated |
| SLA tracking | Response time targets per priority |
| Live chat | Intercom or Crisp — on public frontend |
| Proactive triggers | Auto-open on application/checkout |
| Chatbot first response | FAQ automation |
| Knowledge base | Searchable articles by user type |
| Video walkthroughs | Tutorial videos for key workflows |
| Onboarding wizard — landowner | Property → photos → pricing → template → publish |
| Onboarding wizard — hunter | Profile → license → verify → browse |
| Onboarding wizard — club officer | Create → invite → dues → apply |
| Progress indicators | Visual completion % |
| Re-engagement prompts | Email if onboarding started but not completed in 48 hours |

---

## Module L: Pricing Intelligence & Market Tools

| Feature | Details |
|---|---|
| Comparables analysis | Match against similar listings by state, county, acreage, amenities |
| Suggested price range | Low/mid/high recommendation |
| Price per acre benchmarks | State and county-level from platform data |
| Amenity premiums | Quantify value of cabin, water source, trophy management |
| Seasonal pricing suggestions | Higher rates for peak seasons |
| Demand heatmap | Geographic hunter search activity |
| Search trend data | Most searched species, states, price ranges |
| Supply vs. demand gaps | High demand, low listing counties |
| Dynamic pricing suggestions | Adjust based on time-on-market |
| Auction reserve advisor | Based on comparable fixed-price leases |

---

## Module M: Platform Integrations

### Mapping & Property Data
| Integration | Details |
|---|---|
| OnX Maps | Import parcel boundary data automatically |
| PLSS / county parcel data | Public Land Survey System |
| USGS topo overlay | Topographic layers |
| Satellite imagery | Recent high-resolution imagery |
| Elevation / terrain data | 3D terrain for e-scouting |

### Trail Camera Integrations
| Integration | Details |
|---|---|
| CuddeLink | Pull images from cellular camera network |
| Spartan Camera | Direct image import |
| Stealth Cam Connect | Cloud platform integration |
| Generic FTPS/email import | Fallback for email/FTP cameras |
| AI species tagging | Auto-tag species in imported photos |
| Camera health alerts | Battery level, connectivity alerts |

### Calendar & Scheduling
| Integration | Details |
|---|---|
| Google Calendar sync | Export hunt schedules |
| iCal / Outlook sync | Universal calendar export |
| Club calendar sharing | Shared calendar via iCal subscription |

### Weather & Solunar
| Integration | Details |
|---|---|
| Tomorrow.io API | 7-day forecast per property |
| Barometric pressure | Historical correlated against harvest data |
| Wind direction overlay | Mapbox wind layer for stand selection |
| Solunar tables | Moon phase activity predictions |
| Weather-harvest correlation | Surface patterns over time |

### Developer Tools
| Integration | Details |
|---|---|
| Webhook system | Outbound webhooks on key events |
| REST API | Full public API |
| Zapier integration | No-code automation |
| API key management | User-managed keys |

---

## Module N: Multi-Tenancy, White Label & Scale

### White Label
| Feature | Details |
|---|---|
| Custom domain | White-label tenant on own domain |
| Custom branding | Logo, colors, email templates |
| Feature flag control | Enable/disable modules per tenant |
| Pricing isolation | Tenant sets own pricing |
| Tenant admin portal | Manages own users, properties, leases |
| Revenue sharing | Platform fee vs. partner split |

### Organization Model
| Feature | Details |
|---|---|
| Organization entity | Group multiple properties under management company |
| Organization admin | Org-level visibility |
| Property manager role | Staff assigned to specific properties |
| Org-level billing | Single invoice |
| Consolidated reporting | Across all org properties |

### Internationalization
| Feature | Details |
|---|---|
| Multi-currency | USD primary — CAD for Canada Phase 2 |
| Locale-aware formatting | Dates, currency, units |
| Language framework | i18n — English first, French (CA) ready |
| Multi-timezone | Property timezones respected |

---

## Module O: Waitlist Management

| Feature | Details |
|---|---|
| Per-property waitlist | Hunters join when property is full |
| Position display | Hunter sees queue position |
| Spot opening trigger | Lessee exit → position 1 gets first right of refusal |
| Timed response window | 48–72 hours — auto-advances on no response |
| Auction waitlist | Losing bidders automatically offered waitlist |
| Priority waitlist | Premium tier — pay for priority position |
| Waitlist analytics | Average wait time, conversion rate |
| Cross-property suggestions | Similar available properties shown |

---

## Module P: Lease Negotiation Workflow

| Feature | Details |
|---|---|
| Initial offer | Hunter submits application with proposed terms |
| Counter-offer | Landowner/admin responds with different terms |
| Negotiation thread | Thread-based, timestamped, permanent |
| Term change highlighting | Visual diff between offer and counter-offer |
| Offer expiry | Proposals auto-expire after configurable window |
| Accept / decline / counter | Clear action buttons |
| Negotiation history | Full audit trail per application |
| Final terms lock | Accepted terms auto-populate lease generation |

---

## Module Q: Property Tour & Virtual Walkthrough

| Feature | Details |
|---|---|
| Video upload | Walkthrough videos — processed and hosted |
| 360° photo support | Immersive spherical photos |
| Photo tour builder | Ordered sequence with captions |
| Drone footage support | Large file — chunked resumable upload |
| Virtual tour scheduling | Landowner offers video call — calendar booking |
| Video call integration | Zoom or Google Meet — logged and timestamped |
| Tour history | All tours logged in application record |

---

## Module R: Gamification & Community

### Leaderboards & Achievements
| Feature | Details |
|---|---|
| Season harvest leaderboard | Per-property — opt-in |
| Platform-wide leaderboard | Opt-in — across all members by species |
| Achievement badges | First harvest, 5-year renewal, club officer, habitat contributor |
| Milestone rewards | Unlock platform benefits |
| Sponsored leaderboard | Named and badged for sponsor |

### Community
| Feature | Details |
|---|---|
| Harvest photo gallery | Members share photos — within club or publicly |
| Hunt story log | Narrative hunt report |
| Community feed | Public feed of shared stories |
| Comments & reactions | Member engagement |
| State / species forums | Discussion boards |
| Forum moderation | Admin review queue |
| User-generated SEO content | Posts indexed |

---

## Module S: Privacy & Data Compliance

| Feature | Details |
|---|---|
| Data inventory map | Every data type — collected, stored, retention |
| CCPA compliance center | Right to know, delete, opt-out — formal workflow |
| Right to deletion | PII purge while retaining financial records |
| Data portability export | Full-account export — JSON/PDF |
| Cookie consent management | OneTrust or CookieYes — region-aware |
| Privacy policy versioning | Version-controlled — users re-accept on change |
| Terms of service versioning | Same workflow |
| Data processing agreements | DPAs for every third-party processor |
| COPPA compliance | Under-13 — parental consent, no behavioral data |
| Data breach response plan | Detection, containment, notification workflow |
| Consent audit log | Every consent action logged |
| Data retention enforcement | Automated purge jobs |

---

## Module T: Security Deposit Management

| Feature | Details |
|---|---|
| Deposit collection | Separate Stripe charge — held in escrow |
| Stripe escrow hold | Not disbursed until release approval |
| Pre-lease condition report | Landowner documents condition with timestamped photos |
| Lessee acknowledgment | Lessee signs pre-lease condition report |
| End-of-lease inspection | Post-lease condition report |
| Deposit return workflow | Full, partial, or deduction claim |
| Deduction documentation | Itemized with supporting evidence required |
| Deposit dispute | Feeds into Module W |
| State law compliance | Maximum deposits and return timelines per state |
| Deposit interest | Calculated and disbursed where state-required |

---

## Module U: ADA / WCAG 2.1 Accessibility

| Feature | Details |
|---|---|
| Semantic HTML | Proper heading hierarchy, landmark regions |
| ARIA labels | All interactive elements labeled |
| Keyboard navigation | Every action without mouse — logical tab order |
| Color contrast | Minimum 4.5:1 ratio |
| Screen reader compatibility | NVDA, JAWS, VoiceOver tested |
| Alt text management | Admin workflow for all photos |
| Accessible map layer | Mapbox accessibility mode |
| Focus indicator styling | Visible — never suppressed |
| Accessible form errors | Inline — not color-only |
| Skip navigation links | Screen reader assistance |
| Third-party audit | Pre-launch WCAG audit |
| CI/CD scanning | axe-core automated |

---

## Module V: Early Lease Termination Workflow

| Feature | Details |
|---|---|
| Lessee-initiated | Formal request, notice period, early termination fee |
| Landowner-initiated | Cause vs. no-cause — notice periods and refunds per state |
| Force majeure | Natural disaster, fire, flood, condemnation |
| Prorated refund calculation | Auto-calculated on remaining lease days |
| Termination agreement | Formal document — signed by both parties |
| Access revocation | Immediate — gate codes, portal, member status |
| Smart lock revocation | Digital credentials revoked instantly |
| Security deposit trigger | Initiates deposit return workflow |
| Re-listing trigger | Property returns to available inventory |
| Waitlist notification | Waitlisted hunters notified |

---

## Module W: Platform Dispute Resolution

| Feature | Details |
|---|---|
| Dispute filing | Structured submission — any party |
| Dispute categories | Payment, misrepresentation, access, damage, service, deposit, auction |
| Evidence submission | Documents, photos, messages from both parties |
| Response deadlines | Defined windows — auto-resolved on non-response |
| Admin mediation | Review, apply policy, determine outcome |
| Escalation path | Senior admin or external arbitration |
| Dispute outcomes | Refund, warning, suspension, termination |
| Financial settlement | Stripe-processed automatically |
| Appeals process | Formal criteria and review window |
| Dispute history | Patterns tracked — repeated disputes trigger review |
| Legal hold flag | Litigation freeze on affected data |

---

## Module X: Platform Administration & DevOps

| Feature | Details |
|---|---|
| Admin impersonation | Log in as any user — audit-logged, 2FA, time-limited |
| Feature flag management | LaunchDarkly — per-user, per-tenant, percentage rollout |
| Maintenance mode | Portal-specific with custom messaging |
| A/B testing framework | Conversion measurement |
| Error tracking | Sentry |
| Application performance monitoring | Datadog or New Relic |
| Centralized logging | Papertrail or Loggly |
| Uptime monitoring | External checks — PagerDuty alerting |
| Status page | Public real-time platform status |
| Database backup monitoring | Automated verification + quarterly restore tests |
| CDN configuration | Cloudflare — DDoS, WAF |
| Security headers | CSP, HSTS, X-Frame-Options |
| Dependency scanning | Automated in CI/CD |
| Post-incident reports | Formal RCA |

---

## Module Y: Promo Codes, Discounts & Gift Cards

| Feature | Details |
|---|---|
| Promo code engine | Percentage or flat — usage limits, expiry |
| Referral reward codes | Auto-generated from referral module |
| Seasonal promotions | Time-bounded campaigns |
| Bundle discounts | Cross-module discounting |
| Loyalty discounts | Automatic renewal discounts |
| Gift cards | Purchase and redeem platform credit |
| Gift a lease | Purchase as gift — redeemable voucher |
| Gift a guided hunt | Same voucher mechanism |
| Corporate / group purchasing | Bulk lease purchase — invoice billing |

---

## Module Z: Property Comparison Tool

| Feature | Details |
|---|---|
| Comparison selection | Select 2–4 properties — persistent |
| Side-by-side view | All attributes aligned |
| Attribute highlighting | Best value per row highlighted |
| Save comparison | Bookmark to account |
| Share comparison | Shareable link for club decisions |
| Comparison-to-application | Apply directly from comparison |
| Mobile comparison | Swipeable card view |

---

## Module AA: Saved Search & Listing Alerts

| Feature | Details |
|---|---|
| Saved search profiles | State, species, price, acreage, type |
| New listing alerts | Email + push on match |
| Price drop alerts | Watched property price reduction |
| Availability alerts | Full property opens a spot |
| Back-in-stock alerts | Previously leased property re-lists |
| Auction alerts | New auction matches saved search |
| Outfitter alerts | New matching guided hunt packages |
| Marketplace alerts | Matching marketplace listings |
| Alert frequency | Immediate, daily, weekly digest |

---

## Module AB: Hunter Education & Certification Tracking

| Feature | Details |
|---|---|
| Hunter education certificate | Upload, verify, expiry tracking |
| State-specific requirements | Bowhunter ed, waterfowl ID, trapper ed |
| Firearms safety certification | Landowner-configurable |
| First aid / WFA certification | Configurable requirement |
| ATV / UTV safety certification | Configurable per property |
| Burn boss certification | Texas A&M Fire School |
| Certification upload | Image or PDF |
| Expiry tracking | 60/30/7 day reminders |
| Club compliance report | Officers see member compliance status |
| Lease eligibility gate | Configurable — prevent activation until complete |

---

## Module AC: Platform Health, SLA & Disaster Recovery

| Feature | Details |
|---|---|
| SLA tier definitions | Standard 99.5%, Professional 99.9%, Enterprise 99.95% |
| SLA measurement | Automated monthly calculation — credit on breach |
| Incident communication | Status page — severity, services, ETA |
| Data retention enforcement | Financial 7yr, activity 2yr, deleted accounts 30-day |
| Disaster recovery plan | Documented RTO/RPO — tested annually |
| Backup strategy | Daily dumps + WAL archiving — 5-minute point-in-time |
| Annual penetration test | Third-party — findings to remediation |
| SOC 2 Type II roadmap | Phased compliance path |
| Vulnerability disclosure policy | Public responsible disclosure |
| Security incident response | Documented playbook |

---

## Module AD: Conservation & Environmental Overlays

| Feature | Details |
|---|---|
| FEMA flood zone overlay | 100-year and 500-year zones |
| USFWS wetland delineation | National Wetlands Inventory |
| Conservation easement notation | Active easements — permitted use restrictions |
| Endangered species / critical habitat | USFWS designations |
| USDA soil survey overlay | Food plot suitability, soil type |
| Timber stand overlay | Harvest areas affecting access |
| Invasive species reporting | Feral hog, kudzu, cogon grass, aquatic invasives |
| Invasive aggregation | Anonymized reports to state agencies |
| Prescribed burn coordination | Burn permits, weather windows, certification |
| Layer toggle controls | User controls visible overlays |

---

## Module AE: Offline-First PWA Architecture

| Feature | Details |
|---|---|
| Offline harvest logging | GPS log with no signal — sync on reconnect |
| Offline fishing harvest | Same offline capability |
| Offline check-in / check-out | Local timestamp — syncs on reconnect |
| Offline incident reporting | Capture with photos — sync on reconnect |
| Offline SOS | SMS fallback when no data |
| Offline document access | Cached lease, rules, gate codes, maps |
| Background sync | Service worker handles all pending sync |
| Conflict resolution | Defined strategy for offline vs. server state |
| Sync status indicator | Synced, pending, failed |
| Selective cache management | User controls cached content |

---

## Module AF: Partnership & Integration Ecosystem

### State Wildlife Agency Partnerships
| Feature | Details |
|---|---|
| Data sharing agreements | Formal MOUs with TPWD, GDNR, MDWFP, ADCNR, FWCC |
| Regulation data pipeline | Agency-pushed reg updates |
| Co-branded conservation programs | TPWD MLDP, Georgia QDM |
| Agency harvest reporting portal | Agencies query anonymized dataset via API |

### Hunting Organization Partnerships
| Organization | Details |
|---|---|
| QDMA / NQDMF | Co-marketing, member discounts, educational content |
| NWTF | Turkey content, habitat programs |
| Ducks Unlimited | Waterfowl habitat content, wetland data |
| RMEF | Western states expansion |

### Insurance Carrier Partnerships
| Feature | Details |
|---|---|
| Great American Insurance | Integrated policy quoting in lease application |
| Outdoor Underwriters | Alternative carrier |
| Policy binding | Complete purchase within platform — commission revenue |

---

## Module AG: Harvest Data Monetization

| Feature | Details |
|---|---|
| Data contribution opt-in | Clear disclosed opt-in at signup |
| Anonymization pipeline | PII stripped — cohort ID, GPS generalized to county |
| Dataset schema | Species, date, county, state, weapon, weather, moon phase, habitat |
| Wildlife agency licensing | Aggregated data for population modeling |
| Academic research program | University ecology, CWD, climate impact partnerships |
| Conservation org licensing | DU, QDMA, NWTF |
| Market research licensing | Equipment manufacturers |
| Researcher portal | Aggregated queries only — no raw download |

---

## Module AH: Smart Lock & IoT Integration

| Feature | Details |
|---|---|
| Smart lock integration | LockState, Schlage Connect, CDVI |
| Access credential provisioning | Lease activation auto-generates time-limited credentials |
| Credential expiry | Expires on lease end — automatic |
| Guest access provisioning | Single-day credential per guest pass |
| Club member provisioning | Individual credentials — individual revocation |
| Remote lock / unlock | Admin or landowner — audit-logged |
| Access log | Every gate event — timestamped |
| Unauthorized access alerts | Alert on access outside lease dates |
| QR code fallback | Validates lease, displays static code |
| Code rotation | Auto-rotated at lease end |
| Gate camera integration | Captures vehicle at entry |
| IoT health monitoring | Battery, connectivity alerts |

---

## Module AI: Bundled Insurance Products

| Feature | Details |
|---|---|
| Group hunting liability policy | Platform-negotiated group rate for clubs |
| Per-hunt day coverage | Individual liability at check-in |
| Annual lease liability | Required for lease activation |
| Landowner property coverage | Liability for hosting hunting |
| Equipment coverage | Trail cameras, stands, feeders |
| Trip cancellation | For outfitter bookings |
| Medical evacuation | Medevac for remote properties |
| Policy comparison | Multiple carrier quotes |
| Inline policy purchase | Complete within platform |
| Claims initiation | Triggered from incident report |

---

## Module AJ: Landowner Succession & Estate Planning

| Feature | Details |
|---|---|
| Entity ownership support | LLC, trust, partnership, corporation |
| Entity document storage | Operating agreement, trust document |
| Multiple authorized managers | Entity-owned properties |
| Succession designation | Designate successor manager |
| Succession activation | Death certificate / legal authority workflow |
| Property ownership transfer | Sale or inheritance workflow |
| Lease continuity on sale | Active leases transfer with documentation |
| Lessee notification | Formal notification on ownership change |
| Title document storage | Deed, title insurance, survey plat |
| Capital improvement log | Estate valuation and tax |

---

## Module AK: Agricultural & Land Use Conflict Management

| Feature | Details |
|---|---|
| Crop schedule calendar | Planting/harvest dates — auto-creates blackout recommendations |
| Livestock zone mapping | Active cattle areas on property map |
| Livestock movement alerts | Notify lessees on cattle movements |
| Timber harvest coordination | Active operations — safety exclusion zones |
| Prescribed burn coordination | Mandatory lessee notification — acknowledgment required |
| Agricultural equipment schedule | Operation windows — advisory to lessees |
| Dual-use lease templates | Shared agricultural/hunting use |
| Access priority rules | Configure priority in conflict scenarios |
| Seasonal access calendar | Combined view — hunting, crops, livestock, timber |
| Conflict alert system | Auto-detect booking vs. agricultural conflicts |

---

## Module AL: Sponsorship & Advertising Platform

### Ad Placement Inventory
| Placement | Location | Format |
|---|---|---|
| Featured listings | Public search results | Native sponsored card |
| Search result banners | Top of search results | Display banner |
| Property detail sidebar | Listing detail pages | Display |
| Member portal dashboard | Member home | Native content card |
| Harvest log completion | Post-harvest submission | Full-width contextual |
| Check-in confirmation | Post-hunt check-in | Contextual gear/safety |
| Email digest | Weekly member email | Sponsored section |
| Marketplace categories | Category browse | Sponsored product listings |
| Forum / community | Topic pages | Native sponsored content |
| State / species pages | SEO landing pages | Banner + native |

### Sponsorship Tiers
| Tier | Price Range |
|---|---|
| Title Sponsor — category exclusive | $50K–$250K/year |
| Category Sponsor — owns content area | $10K–$50K/year |
| Standard Self-Serve — CPM/CPC | $500+/month |

### Self-Serve Advertiser Portal
| Feature | Details |
|---|---|
| Advertiser registration | Business account — admin verified |
| Campaign creation | Objective, budget, dates, targeting, creative |
| Targeting options | State/county, species interest, user type, device, tier |
| Creative review | Admin review before serving |
| Budget management | Daily cap, total cap, auto-pause |
| Real-time dashboard | Impressions, clicks, CTR, spend — hourly |
| Conversion tracking | Lease inquiries, applications, purchases attributed |

### Compliance & UX Protections
| Rule | Details |
|---|---|
| Clear labeling | All ads labeled "Sponsored" or "Advertisement" |
| Frequency caps | Per-user per-day limit |
| No ads on critical flows | Lease signing, payment, SOS, incident reporting |
| Ad-free tier | Highest subscription tier — fully ad-free |
| No behavioral data sale | Only anonymized segments |
| COPPA accounts | Never shown behavioral advertising |

---

## Module AM: Fishing Rights Management

### Property Configuration
| Feature | Details |
|---|---|
| Fishing rights designation | Included, excluded, or separate lease |
| Water body registry | All ponds, creeks, rivers, tanks — species present |
| Stocking records | Landowner logs stocking events |
| Fishing access type | Bank only, boat, kayak — configurable per water body |
| Dock and boat ramp registry | Infrastructure mapped |

### Fishing Lease Management
| Feature | Details |
|---|---|
| Fishing-only lease type | Full lease workflow for fishing-only access |
| Bundled lease | Fishing included in hunting lease — separate pricing |
| Fishing quota management | Bass size limits, crappie limits, catfish quotas |
| Fishing season calendar | State-specific freshwater/saltwater seasons |
| Fishing license verification | Separate from hunting — freshwater/saltwater endorsements |
| Fishing rules document | Trotlines, rod limits, bait restrictions |

### Member Fishing Features
| Feature | Details |
|---|---|
| Fishing harvest logging | Species, weight, length, GPS, catch-and-release — offline capable |
| Fishing quota tracker | Per-species limits with remaining display |
| Water body map | Zones, no-go areas, best spots |
| Fishing schedule | Book fishing dates — conflict detection |
| Aquatic invasive reporting | Hydrilla, giant salvinia, zebra mussels |

---

## Module AN: Trust, Safety & Content Moderation

### User Trust System
| Feature | Details |
|---|---|
| Trust score calculation | Verification, lease history, ratings, disputes, payment reliability |
| Trust score display | Visible on profiles and listings |
| Trusted seller badge | Consistent ratings, no disputes |
| Landowner trust profile | Response rate, acceptance rate, dispute rate |

### Content Moderation
| Feature | Details |
|---|---|
| User reporting system | Report user, listing, review, content |
| Content moderation queue | Admin review — approve, remove, escalate |
| Automated content filtering | Profanity, hate speech detection |
| Fake listing detection | Duplicate photos, price anomalies, suspicious patterns |
| Review authenticity | Only verified lessees can review |
| Community guidelines | Published, versioned, acknowledged at registration |

### Account Safety
| Feature | Details |
|---|---|
| Banned user management | Permanent ban, suspension, shadow ban |
| Device fingerprinting | Prevent re-registration by banned users |
| Escalation to law enforcement | Formal workflow for illegal activity |

---

## Module AO: NOAA Emergency Weather & Natural Disaster Alerts

| Feature | Details |
|---|---|
| NOAA NWS integration | Severe weather alerts per property GPS |
| Tornado / severe thunderstorm | Push to all checked-in hunters |
| Flash flood alerts | Critical for creek and river properties |
| Wildfire proximity alerts | USFS fire perimeter — alert within X miles |
| Hurricane / tropical storm | Gulf Coast and Atlantic advance warning |
| Hard freeze alerts | Cabin property pipe protection |
| Drought / burn ban | County burn ban auto-updated |
| Alert acknowledgment | Checked-in hunters must acknowledge |
| Emergency property closure | Admin/landowner sets emergency closed — all access revoked |

---

## Module AP: File Security — Virus & Malware Scanning

| Feature | Details |
|---|---|
| Virus scanning on all uploads | ClamAV or VirusTotal — every file before storage |
| Malicious file blocking | Executables, macro-enabled docs blocked |
| File type validation | Server-side MIME type verification |
| File size limits | Enforced per type per context |
| Quarantine workflow | Flagged files quarantined — uploader notified |
| Scan result logging | Every result logged against upload record |
| Retroactive scanning | Historical uploads re-scanned on definition updates |
| Image EXIF stripping | GPS and device metadata removed |

---

## Module AQ: ACH & Alternative Payment Methods

| Feature | Details |
|---|---|
| ACH bank transfer | Stripe ACH — preferred for large annual payments |
| ACH verification | Micro-deposit or Plaid instant bank verification |
| Wire transfer handling | Manual reconciliation workflow |
| Check payment recording | Admin records manual payments |
| Payment plan configuration | Custom installment schedules |
| Affirm / BNPL | Buy-now-pay-later for large lease amounts |
| Payment method management | Multiple saved methods — default per transaction type |
| ACH failure handling | NSF handling, retry logic, grace period |

---

## Module AR: AML / OFAC Compliance

| Feature | Details |
|---|---|
| OFAC screening | Sanctions list at registration and high-value transactions |
| AML transaction monitoring | Flag unusual payment patterns |
| Suspicious activity reporting | Internal SAR workflow — legal counsel review |
| PEP screening | Politically Exposed Person check |
| Transaction velocity monitoring | Unusual frequency — auto-hold |
| Foreign national handling | Additional KYC for non-US users |
| Compliance audit log | All screening results — immutable |

---

## Module AS: SAML / SSO for Enterprise & White Label

| Feature | Details |
|---|---|
| SAML 2.0 support | Azure AD, Okta, Ping Identity |
| OAuth 2.0 / OIDC | Standard SSO for mid-market |
| Just-in-time provisioning | Accounts auto-created on first SSO login |
| Group / role mapping | SSO groups mapped to platform RBAC |
| Social login | Google, Apple, Facebook |
| Magic link authentication | Passwordless email login |
| TOTP / Authenticator MFA | Google Authenticator, Authy |
| Hardware security keys | YubiKey for high-privilege admin accounts |
| Session management | Configurable duration and idle timeout per tenant |

---

## Module AT: Video Processing Pipeline

| Feature | Details |
|---|---|
| Video transcoding | FFmpeg — 1080p, 720p, 480p |
| HLS streaming | HTTP Live Streaming — adaptive bitrate |
| Thumbnail generation | Auto-extract frames — admin selects hero |
| Async processing queue | Background job — uploader notified on completion |
| Chunked upload | Resumable for large drone footage |
| Storage tiering | Hot for recent, cool/archive for older |
| CDN delivery | Cloudflare Stream or Azure CDN |
| Video moderation | Automated screening before publishing |

---

## Module AU: Platform Business Intelligence & Analytics

| Feature | Details |
|---|---|
| Executive KPI dashboard | MRR, ARR, churn, CAC, LTV, GMV — daily |
| Cohort analysis | Retention by acquisition cohort |
| Conversion funnel | Listing view → lease signed — drop-off per stage |
| Revenue attribution | Acquisition channel vs. LTV |
| NPS system | Automated surveys at key moments |
| CSAT surveys | After support, outfitter, consulting |
| Churn analysis | Exit survey correlated against behavioral data |
| Property performance scoring | Conversion, renewal, ratings |
| Platform health scorecard | Weekly automated digest |
| Custom report builder | Admin builds from available dimensions |

---

## Module AV: Carbon Credits & Conservation Finance

| Feature | Details |
|---|---|
| Carbon sequestration calculator | Estimate potential — acreage, timber, land use |
| Conservation program eligibility | USDA EQIP, CRP, RCPP |
| Carbon credit broker connections | Verified brokers — referral revenue |
| Conservation easement tracking | Documents, appraiser connections, tax deductions |
| Wildlife habitat tax valuation | Texas 1-d-1 wildlife management support |
| Carbon credit revenue tracking | Payments, agreements, credit retirement |
| Conservation documentation | Activity logs in agency formats |

---

## Module AW: Club Recruitment Board

| Feature | Details |
|---|---|
| Club listings | Clubs post open membership — species, location, dues, culture |
| Hunter profiles | Hunters post seeking a club |
| Matching engine | Suggest compatible clubs based on preferences |
| Application to club | Direct application through platform |
| Club waitlist | Join when full |
| Club reviews | Former members review clubs they have left |
| Standardized disclosure | Size, dues, species, location — consistent |

---

## Module AX: Veteran & Military Programs

| Feature | Details |
|---|---|
| Military / veteran verification | ID.me or DD-214 upload |
| Veteran discount tier | Configurable discount on subscriptions and lease fees |
| Veteran-owned landowner badge | Visible on listings |
| Veterans hunt program | Free/discounted day hunts donated by landowners |
| Hunting for Heroes integration | API connection |
| Military deployment lease pause | Payment pause — documentation workflow |

---

## Module AY: Youth & Junior Hunter Programs

| Feature | Details |
|---|---|
| Junior hunter account | Age 12–17 — full minor protections, guardian oversight |
| Youth hunt events | Landowners and clubs designate youth opportunities |
| Mentor matching | Experienced hunters matched with youth |
| First harvest celebration | Special harvest log — shareable card |
| Youth pricing tier | Configurable reduced pricing |
| School group / 4-H integration | Group booking for youth organizations |
| Youth safety requirements | Hunter ed mandatory, guardian logged |
| Youth harvest reporting | State-specific youth harvest formats |

---

## Module AZ: Exotic Game & High-Fence Operations

| Feature | Details |
|---|---|
| High-fence property designation | Separate property type with distinct lease terms |
| Exotic species registry | Axis, blackbuck, fallow, sika, red stag, aoudad, nilgai |
| Exotic harvest pricing | Per-animal trophy fees at harvest submission |
| Trophy fee management | Collected separately from lease fee |
| TPWD exotic regulations | Lease templates reflect unregulated exotic status in Texas |
| High-fence maintenance log | Fence inspection records |
| Breeding program records | Deer breeder module integration |
| Trophy scoring integration | Boone & Crockett, SCI score entry |

---

## Module BA: Extended Stay & Camp Management

| Feature | Details |
|---|---|
| Camp registration | Structure location, type, size |
| Camp rules enforcement | Height limits, materials, seasonal removal |
| Utility hookup tracking | Electrical, water, septic |
| Camp inspection workflow | Pre and post-season condition |
| Camp insurance | Personal property coverage for structures |
| Shared camp management | Club-owned camp — maintenance, supply inventory |
| Camp rental | Landowner offers as add-on — separate pricing |
| Camp removal compliance | End-of-lease verification with photos |

---

## Module BB: Bulk Import & Data Migration Tools

| Feature | Details |
|---|---|
| Spreadsheet import | Excel/CSV — lessees, properties, leases |
| Historical lease import | Historical records for reporting continuity |
| Competitor migration | Import format support for HLRBO, American Headhunter App |
| Legacy document import | Bulk PDF — OCR extraction of key terms |
| Contact import | Existing lessee contacts — sends platform invitations |
| Import validation | Pre-import quality report — errors, duplicates |
| Rollback capability | Full rollback to pre-import state |
| Migration assistance service | White-glove managed migration as paid service |

---

## Module BC: Mineral & Timber Rights Tracking

| Feature | Details |
|---|---|
| Mineral rights designation | Surface only, surface + mineral, severed minerals |
| Oil and gas lease overlay | Existing O&G leases on property map |
| Timber rights designation | Who holds timber rights |
| Active timber harvest alerts | Integrate with Module AK |
| Royalty income tracking | In landowner financial module |
| Rights encumbrance display | Full disclosure to hunting lessees |

---

## Module BD: Platform Automation & Workflow Engine

| Feature | Details |
|---|---|
| Visual workflow builder | If/then automation — no-code |
| Trigger library | Lease signed, payment, harvest, expiring, application, etc. |
| Action library | Send email, notification, create task, update status, generate document |
| Automation templates | 90-day renewal, new member welcome, payment failure recovery |
| Workflow testing | Sandbox before activating |
| Automation audit log | Every automated action logged |
| Conditional logic | AND/OR multi-condition triggers |
| Delay actions | Schedule actions after defined time window |

---

## Module BE: Public API Developer Platform

| Feature | Details |
|---|---|
| Public API documentation | OpenAPI/Swagger — docs.yourplatform.com |
| Developer portal | Self-service key management, usage dashboard |
| Sandbox environment | Full test environment with synthetic data |
| API versioning | Formal v1/v2 — deprecation notices |
| SDK libraries | Official PHP, JavaScript, Python |
| Webhook documentation | Complete event catalog, payload schemas |
| Partner program | Formal application — partner directory |
| Integration marketplace | Third-party integrations discoverable in platform settings |

---

## Module BF: Wildlife Photography & Observation Tourism

| Feature | Details |
|---|---|
| Observation listing type | Non-hunting wildlife experience properties |
| Photography blind booking | Book blinds for wildlife photography |
| Birding access | Properties with notable bird species |
| Nature/hiking access | Day-use land access |
| Wildlife photography gallery | Member-contributed wildlife photos per property |
| Cross-audience marketing | Photography groups, birding clubs, conservation orgs |
| Seasonal wildlife events | Elk rut, turkey gobbling, shorebird migrations — bookable |

---

## Module BG: QR Code Physical Integration

| Feature | Details |
|---|---|
| Property QR code | Unique per property — printable, weatherproof |
| QR check-in | Scan at entrance — validates, logs check-in |
| QR rules display | Current rules, season dates, emergency contacts |
| QR emergency contact | Nearest hospital, game warden — no login required |
| QR gate code reveal | Active membership validation → gate code displayed |
| Trespasser notice QR | Captures device info, timestamps, alerts landowner |
| Stand QR codes | Scan to log stand selection |
| Equipment QR tagging | Tag feeders, cameras, stands — scan to log maintenance |

---

## Module BH: Print & Offline Document Generation

| Feature | Details |
|---|---|
| Print-friendly lease packets | Optimized print — lease, rules, maps, emergency contacts |
| Offline registration packets | Complete printable onboarding packet |
| Welcome packet | Custom-branded new lessee welcome document |
| Emergency card | Wallet-sized — property address, GPS, emergency contacts, warden |
| Posting signs | No-trespass, lease property signs with QR codes |
| Annual report | End-of-year printable summary for landowners |
| Club roster packet | Member roster with contact info and certification status |

---

## Module BI: Cellular Dead Zone Mapping

| Feature | Details |
|---|---|
| Cellular coverage overlay | T-Mobile, AT&T, Verizon coverage maps on property |
| Dead zone notation | Property detail page shows known connectivity issues |
| Satellite communicator integration | Garmin inReach, SPOT — for zero-cellular properties |
| Community-reported coverage | Members report actual coverage at GPS points |
| Offline readiness indicator | Recommends content to cache before entering dead zones |

---

## Shared Services Layer

### Payment Processing — Full Capability
| Capability | Implementation |
|---|---|
| Standard card payments | Stripe Checkout |
| ACH bank transfer | Stripe ACH + Plaid |
| Recurring subscriptions | Stripe Subscriptions |
| Installment plans | Custom Stripe payment schedules |
| BNPL | Affirm via Stripe |
| Marketplace payouts | Stripe Connect |
| Escrow holds | Stripe holds — consulting, security deposits |
| Trophy fees | Collected at harvest log submission |
| Micro-insurance | Per-hunt day coverage at check-in |
| Gift cards | Platform credit — Stripe balance |
| Refunds | Full and partial — admin-initiated |
| Wire/check recording | Manual payment records |
| Tax compliance | Tax1099 API |

### Document Management — Full Capability
| Capability | Implementation |
|---|---|
| Lease template engine | Merge-field templates per state per type |
| Template versioning | Full change history |
| Auto-generation | PDF on approval |
| e-Signature | Dropbox Sign — ESIGN/UETA compliant |
| Executed document archive | Per-lessee per-property — immutable |
| Supporting doc uploads | License, insurance, ID, certifications |
| Addendum workflow | Generate, notify, re-sign |
| Bulk generation | Mass renewal packets |
| File storage | Azure Blob — tiered |
| Virus scanning | ClamAV / VirusTotal on every upload |
| EXIF stripping | GPS and device metadata removed |
| Video processing | FFmpeg — HLS streaming |
| Print generation | Optimized layouts for all document types |

### Notification System — Full Capability
| Channel | Use Cases |
|---|---|
| Email | All transactional — applications, approvals, payments, expiry, 1099s |
| In-app | Real-time alerts across all portals |
| Push (PWA) | Hunt alerts, broadcasts, payment due, weather emergencies |
| SMS (Twilio) | Critical safety alerts, SOS confirmation, 2FA |
| Offline queue | Notifications queued when offline — delivered on reconnect |

### Security Architecture — Full Stack
| Layer | Implementation |
|---|---|
| Authentication | Laravel Jetstream + SAML 2.0 + OAuth/Socialite |
| MFA | SMS OTP, TOTP authenticator, hardware security keys |
| Authorization | Spatie Laravel-Permission — RBAC |
| Database security | PostgreSQL Row-Level Security per tenant |
| Field encryption | pgcrypto — gate codes, sensitive data |
| File security | ClamAV virus scanning + EXIF stripping |
| Signed URLs | Time-limited — no public document links |
| Audit trail | Immutable audit_logs — all mutations |
| OFAC/AML | Sanctions screening at registration and transactions |
| Rate limiting | Valkey-backed per-user API throttling |
| TLS/HSTS | Enforced everywhere |
| WAF | Cloudflare WAF rules |
| Security headers | CSP, X-Frame-Options, X-Content-Type-Options |
| Dependency scanning | Automated in CI/CD |
| Annual pentest | Third-party penetration testing |
| SOC 2 Type II | Roadmap to compliance |

---

## Complete Phase Delivery Plan

| Phase | Modules | Scope |
|---|---|---|
| **1** | Core | Admin backend, property CRUD, customer application flow, Stripe card payments, document upload, onboarding wizards, support ticketing, basic RBAC |
| **2** | F, T, U, V | Legal compliance + state templates, security deposits, ADA/WCAG, early termination |
| **3** | Core Member Portal | Lease lifecycle, e-signature, scheduling, renewal, negotiation (P), waitlist (O) |
| **4** | Q, Z, AA | Public frontend, virtual tours, property comparison, saved search and alerts |
| **5** | SEO/Marketing Infra | SEO architecture, state/county/species pages, GA4, Meta Pixel, GTM, social sharing |
| **6** | E, AW | Hunting club/group leases, club recruitment board |
| **7** | G, AB, AO | Incident and emergency, hunter certifications, NOAA weather alerts |
| **8** | Wildlife Core | Harvest logging, quota, stand registry, CWD compliance, season calendar |
| **9** | AM | Fishing rights management |
| **10** | AZ, BA | Exotic game / high-fence, extended stay / camp management |
| **11** | A | Auction module — bidding engine, proxy bids, WebSocket real-time, winner flow |
| **12** | H, S, AR | Financial/tax compliance, privacy/data compliance, AML/OFAC |
| **13** | W, AC | Dispute resolution, platform SLA and disaster recovery |
| **14** | X, BD | Platform DevOps tooling, automation/workflow engine |
| **15** | AP, AT | File virus scanning, video processing pipeline |
| **16** | AQ, AS | ACH / alternative payments, SAML / SSO |
| **17** | I, AF | Regulatory compliance, agency and org partnerships |
| **18** | B | Consulting marketplace |
| **19** | C | Outfitter and guide booking |
| **20** | D | Equipment and services marketplace |
| **21** | AH | Smart lock and IoT integration |
| **22** | AI | Bundled insurance products |
| **23** | L, AU | Pricing intelligence, platform BI and analytics |
| **24** | J, Y | Marketing and growth tools, promo codes, gift cards |
| **25** | K | Customer support infrastructure |
| **26** | AD, AK | Conservation overlays, agricultural conflict management |
| **27** | AJ, BC | Landowner succession, mineral and timber rights |
| **28** | AN | Trust, safety, and content moderation |
| **29** | R | Gamification and community |
| **30** | AG | Harvest data monetization |
| **31** | AL | Sponsorship and advertising platform |
| **32** | AV | Carbon credits and conservation finance |
| **33** | AX, AY | Veteran/military programs, youth/junior programs |
| **34** | BF | Wildlife photography and observation tourism |
| **35** | BG, BH | QR code physical integration, print/offline document generation |
| **36** | BI | Cellular dead zone mapping |
| **37** | BB | Bulk import and data migration tools |
| **38** | N | Multi-tenancy and white label |
| **39** | M | Platform integrations — OnX, trail cameras, calendar, weather, Zapier |
| **40** | AE | Offline-first PWA — full offline model, background sync, conflict resolution |
| **41** | BE | Public API developer platform — docs, sandbox, SDKs, partner program |
| **42** | PWA | Mobile PWA hardening — push notifications, SOS, offline maps, QR check-in |
| **43** | Native App | React Native or Flutter native app evaluation and build |
| **44** | AI Layer | Trail cam recognition, smart lease matching, churn prediction, fraud detection, dynamic pricing, NLP lease review, support chatbot |

---

## Complete Module Index

| ID | Module | Category |
|---|---|---|
| — | Public Frontend | Core Portal |
| — | Customer Portal | Core Portal |
| — | Member Portal | Core Portal |
| — | Admin Backend | Core Portal |
| — | Reporting Suite | Core Portal |
| A | Auction-Based Lease Bidding | Monetization |
| B | Habitat & Wildlife Consulting Marketplace | Services |
| C | Outfitter & Guide Booking | Services |
| D | Equipment & Services Marketplace | Commerce |
| E | Hunting Club / Group Lease Management | Operations |
| F | Legal & Compliance Architecture | Legal |
| G | Incident & Emergency Management | Safety |
| H | Financial & Tax Compliance | Finance |
| I | Regulatory & Wildlife Compliance | Compliance |
| J | Marketing & Growth Tools | Growth |
| K | Customer Support Infrastructure | Operations |
| L | Pricing Intelligence & Market Tools | Intelligence |
| M | Platform Integrations | Integrations |
| N | Multi-Tenancy & White Label | Scale |
| O | Waitlist Management | Conversion |
| P | Lease Negotiation Workflow | Operations |
| Q | Property Tour & Virtual Walkthrough | Discovery |
| R | Gamification & Community | Retention |
| S | Privacy & Data Compliance | Legal |
| T | Security Deposit Management | Finance |
| U | ADA / WCAG 2.1 Accessibility | Legal |
| V | Early Lease Termination | Operations |
| W | Platform Dispute Resolution | Legal |
| X | Platform Administration & DevOps | Infrastructure |
| Y | Promo Codes, Discounts & Gift Cards | Monetization |
| Z | Property Comparison Tool | Discovery |
| AA | Saved Search & Listing Alerts | Conversion |
| AB | Hunter Education & Certification Tracking | Compliance |
| AC | Platform Health, SLA & DR | Infrastructure |
| AD | Conservation & Environmental Overlays | Data |
| AE | Offline-First PWA Architecture | Mobile |
| AF | Partnership & Integration Ecosystem | Growth |
| AG | Harvest Data Monetization | Revenue |
| AH | Smart Lock & IoT Integration | Operations |
| AI | Bundled Insurance Products | Revenue |
| AJ | Landowner Succession & Estate Planning | Legal |
| AK | Agricultural & Land Use Conflict Management | Operations |
| AL | Sponsorship & Advertising Platform | Revenue |
| AM | Fishing Rights Management | Vertical |
| AN | Trust, Safety & Content Moderation | Safety |
| AO | NOAA Emergency Weather Alerts | Safety |
| AP | File Security — Virus & Malware Scanning | Security |
| AQ | ACH & Alternative Payment Methods | Finance |
| AR | AML / OFAC Compliance | Legal |
| AS | SAML / SSO for Enterprise | Enterprise |
| AT | Video Processing Pipeline | Infrastructure |
| AU | Platform Business Intelligence & Analytics | Intelligence |
| AV | Carbon Credits & Conservation Finance | Revenue |
| AW | Club Recruitment Board | Community |
| AX | Veteran & Military Programs | Community |
| AY | Youth & Junior Hunter Programs | Community |
| AZ | Exotic Game & High-Fence Operations | Vertical |
| BA | Extended Stay & Camp Management | Operations |
| BB | Bulk Import & Data Migration Tools | Onboarding |
| BC | Mineral & Timber Rights Tracking | Data |
| BD | Platform Automation & Workflow Engine | Operations |
| BE | Public API Developer Platform | Scale |
| BF | Wildlife Photography & Observation Tourism | Vertical |
| BG | QR Code Physical Integration | Operations |
| BH | Print & Offline Document Generation | Operations |
| BI | Cellular Dead Zone Mapping | Safety |

---

## What This Platform Is

This is not a hunting lease CMS. It is a **full vertical SaaS platform** combining:

- A **two-sided marketplace** — landowners and hunters
- A **legal document platform** — leases, signatures, state compliance
- A **financial platform** — payments, escrow, payouts, tax, AML compliance
- A **wildlife management system** — harvest, quotas, herd data, fishing
- A **safety platform** — incident management, SOS, IoT access, emergency weather
- A **community platform** — clubs, forums, gamification, recruitment
- A **commerce platform** — marketplace, auctions, outfitter bookings
- A **consulting platform** — habitat services, attorney review, conservation finance
- A **data business** — harvest dataset, pricing intelligence, advertising audience
- An **enterprise platform** — white label, multi-tenancy, SOC 2, developer API

**There is no platform like this in the hunting industry today.**

---

## Database Architecture

### Design Philosophy

Every database boundary is a **security blast radius limiter**. If one database is compromised, breached, or has an application bug that leaks data, the damage is contained to only what that database holds.

Database separation enables:
- Independent encryption keys per database — rotate one without touching others
- Scoped third-party access — auditors, insurers, wildlife agencies see only what they need
- Independent scaling per load characteristic — geospatial queries never compete with billing transactions
- Clean compliance boundaries — PCI DSS, CCPA, SOC 2 requirements mapped to specific databases
- Independent backup frequency and retention policies per sensitivity level
- Service boundary enforcement — prevents cross-domain spaghetti queries at the architecture level

**The cross-database rule:** No cross-database SQL joins. All multi-database queries are handled at the application service layer — query each database separately, join in PHP/Laravel service classes. The Analytics DB (DB 8) pre-joins and denormalizes via ETL so reports never hit multiple production databases simultaneously.

---

### Laravel Multi-Database Configuration

Laravel natively supports multiple database connections with zero framework changes:

```php
// config/database.php
'connections' => [
    'identity'       => [...PostgreSQL connection 1...],
    'property'       => [...PostgreSQL connection 2...],
    'lease'          => [...PostgreSQL connection 3...],
    'billing'        => [...PostgreSQL connection 4...],
    'wildlife'       => [...PostgreSQL connection 5...],
    'commerce'       => [...PostgreSQL connection 6...],
    'communications' => [...PostgreSQL connection 7...],
    'analytics'      => [...PostgreSQL connection 8...],
    'audit'          => [...PostgreSQL connection 9...],
    'incidents'      => [...PostgreSQL connection 10...],
    'documents'      => [...PostgreSQL connection 11...],
    'platform'       => [...PostgreSQL connection 12...],
    'geospatial'     => [...PostgreSQL connection 13 — PostGIS instance...],
    'research'       => [...PostgreSQL connection 14 — air-gapped...],
]

// Models declare their connection explicitly
class Lease extends Model {
    protected $connection = 'lease';
}

class Payment extends Model {
    protected $connection = 'billing';
}

class PropertyBoundary extends Model {
    protected $connection = 'geospatial';
}
```

**Application-layer join pattern:**
```php
// LeaseService — assembles composite objects across databases
class LeaseService {
    public function getFullLeaseSummary(string $leaseId): LeaseSummaryDTO
    {
        $lease    = Lease::on('lease')->findOrFail($leaseId);
        $user     = UserProfile::on('identity')->find($lease->user_id);
        $property = Property::on('property')->find($lease->property_id);
        $payment  = PaymentStatus::on('billing')->where('lease_id', $leaseId)->latest()->first();

        return new LeaseSummaryDTO($lease, $user, $property, $payment);
    }
}
```

Individual lookups are cached in Valkey — the performance overhead of cross-database assembly is negligible for most operations.

---

### DB 1: Identity & Authentication

**Purpose:** Everything about who someone IS — credentials, PII, roles, trust

**Security tier:** Maximum — independent encryption key, strictest access controls
**PostgreSQL instance:** Dedicated high-security server
**Encryption key:** Key A — rotated quarterly

```
Tables:
├── users                    (id, email, password_hash, status, account_type, created_at)
├── user_profiles            (name, DOB, address, phone — all PII fields)
├── guardian_relationships   (minor_id → guardian_id links)
├── user_mfa                 (MFA methods, encrypted TOTP secrets)
├── sessions                 (active session tokens — short TTL)
├── password_resets          (reset tokens — short TTL)
├── social_logins            (OAuth provider, provider_user_id)
├── saml_identities          (enterprise SSO tenant links)
├── api_keys                 (hashed keys, scopes, rate limits)
├── roles                    (Spatie RBAC — role definitions)
├── permissions              (Spatie RBAC — permission definitions)
├── user_roles               (user → role assignments)
├── role_permissions         (role → permission assignments)
├── trusted_devices          (device fingerprints for MFA bypass)
├── banned_users             (ban records, device fingerprints, reason)
├── trust_scores             (calculated reputation scores per user)
├── veteran_verifications    (ID.me verification records)
├── identity_verification    (Checkr background check results)
├── ofac_screening_results   (sanctions screening per user)
└── data_deletion_requests   (CCPA right-to-deletion workflow)
```

**Access grants:**
- Application auth service — read/write
- Admin impersonation service — scoped read, audit-logged
- CCPA compliance service — deletion workflow only
- No direct access from any other application service

**Why isolated:**
- All PII lives here — CCPA deletion operations fully scoped to this database
- Breaching this DB reveals identity but nothing about what users own, owe, or have done
- COPPA-regulated under-13 data requires strictest access controls
- Credential rotation and session invalidation operations isolated from business logic

---

### DB 2: Property & Land

**Purpose:** Everything about the physical land — not who leases it, not what it costs

**Security tier:** Standard
**PostgreSQL instance:** High-memory server (large text fields, JSON, many joins)
**Encryption key:** Key B

```
Tables:
├── properties               (id, title, acreage, state, county, status, landowner_id)
├── property_custom_fields   (JSONB dynamic field values per property)
├── property_photos          (metadata — files in Azure Blob)
├── property_videos          (metadata — files in Azure CDN)
├── property_infrastructure  (stands, blinds, feeders, cabins, roads — typed registry)
├── water_bodies             (ponds, creeks, rivers per property)
├── stocking_records         (fish stocking events per water body)
├── camp_registrations       (lessee camp structures per property)
├── mineral_rights           (surface/mineral/timber rights designations)
├── conservation_easements   (active easements, permitted use restrictions)
├── agricultural_calendar    (crop schedules, livestock zones, timber operations)
├── property_availability    (open/closed dates, blackout periods)
├── property_pricing         (per-hunter, per-acre, seasonal pricing configs)
├── exotic_species_config    (high-fence designations, exotic species, trophy fee rates)
├── cellular_coverage_notes  (admin and community-reported dead zones)
├── property_qr_codes        (QR code assignments per property/stand/equipment)
├── carbon_credit_data       (sequestration estimates, program eligibility)
├── property_improvements    (capital improvement log)
└── property_succession      (designated successor managers)
```

**Access grants:**
- Application read/write — all services
- Public listing API — read-only, filtered to active/published properties
- Wildlife agency partners — read-only, specific fields only (no landowner PII)
- Landowner portal — row-level security to own properties only

**Why isolated:**
- High read volume from public listing pages — scales independently
- No financial or PII data — can be shared more broadly with partners
- Landowner access scoped via PostgreSQL Row-Level Security — landowner only touches own records
- PostGIS geometry fields moved entirely to Geospatial DB (DB 13) — this DB holds metadata only

---

### DB 3: Lease & Contract

**Purpose:** The legal relationship between a user and a property — all lifecycle stages

**Security tier:** High — legal records, immutable audit requirement
**PostgreSQL instance:** Dedicated server with append-only enforcement on key tables
**Encryption key:** Key C

```
Tables:
├── lease_applications       (application pipeline — status, submitted_at, reviewed_at)
├── lease_negotiations       (counter-offer threads per application)
├── negotiation_messages     (individual offer/counter messages — immutable)
├── leases                   (executed lease records — core table)
├── lease_custom_terms       (JSONB dynamic clause values per lease)
├── lease_templates          (template library — one per state per lease type)
├── lease_template_versions  (full version history per template)
├── lease_template_clauses   (individual clause library)
├── lease_addenda            (mid-lease formal addenda)
├── lease_signatories        (who signed, which document version, when)
├── signature_events         (IP, timestamp, device, geolocation per signing event)
├── lease_renewals           (renewal records linked to originating lease)
├── early_terminations       (termination records, reason, financial settlement)
├── club_leases              (club entity as lessee — links to club in Identity DB)
├── club_members             (club membership records per lease)
├── club_governance          (votes, bylaws, officer elections, meeting notes)
├── member_sub_agreements    (individual member waivers within club lease)
├── guest_passes             (guest access grants — per member per visit)
├── stand_assignments        (member → stand assignments per season)
├── hunt_schedules           (booked hunt dates per lessee per property)
├── check_in_log             (hunt check-in/check-out records — timestamped)
├── security_deposit_records (deposit amounts, escrow status, return records)
├── attorney_reviews         (attorney review requests and status)
└── waitlist_entries         (waitlist per property and per auction)
```

**Access grants:**
- Application lease service — read/write
- Legal auditors — read-only, time-limited credentials
- Attorney partners — scoped read to their own review assignments
- e-Signature service (Dropbox Sign) — webhook write for signature events only

**Why isolated:**
- Legal records require immutable audit capability — key tables are append-only
- e-Signature audit trails are legally sensitive — Dropbox Sign webhooks write here
- Lease exists independently of payment — financial settlement handled separately in Billing DB
- Attorney-client review records need tight access controls
- 7-year minimum retention managed independently of other databases

---

### DB 4: Billing & Payments

**Purpose:** All financial transactions, payment records, and tax compliance data

**Security tier:** PCI DSS tier — highest financial security
**PostgreSQL instance:** Dedicated PCI-compliant server, isolated network segment
**Encryption key:** Key D — HSM-backed, rotated monthly

```
Tables:
├── payment_methods          (Stripe payment method IDs — tokenized only, no raw card data)
├── invoices                 (generated invoices per lease/service/order)
├── invoice_line_items       (individual line items per invoice)
├── payments                 (payment records — Stripe charge IDs, amounts, status)
├── payment_attempts         (retry history per invoice)
├── payment_plans            (custom installment schedules)
├── payment_plan_installments(individual installments per plan)
├── subscriptions            (Stripe subscription records)
├── subscription_history     (status transitions per subscription)
├── stripe_connect_accounts  (landowner/consultant/outfitter/seller payout accounts)
├── payouts                  (Stripe Connect disbursement records)
├── payout_schedules         (configured payout timing per account)
├── escrow_holds             (consulting and security deposit holds)
├── escrow_releases          (hold release records)
├── trophy_fee_charges       (per-harvest fee trigger records)
├── refunds                  (refund records — amounts, reasons, status)
├── promo_codes              (discount code definitions)
├── promo_redemptions        (per-user redemption records)
├── gift_card_balances       (gift card issuance and balance records)
├── gift_card_redemptions    (redemption history)
├── revenue_recognition      (deferred revenue schedules per lease)
├── revenue_recognition_log  (monthly recognition events)
├── tax_collection           (sales tax collected per transaction)
├── tax_nexus_tracking       (economic nexus thresholds per state)
├── tax_remittance_records   (state remittance history)
├── w9_records               (TIN/EIN — encrypted with Key D)
├── tax_1099_records         (generated 1099-NEC and 1099-K records)
├── tax_filing_log           (IRS e-filing submission records)
├── landowner_expenses       (property expense logs for P&L)
├── carbon_credit_revenue    (conservation finance payments)
├── affiliate_commissions    (earned commissions per affiliate)
├── affiliate_payouts        (commission disbursement records)
├── advertising_billing      (advertiser campaign spend records)
└── insurance_premium_records(insurance policy payment records)
```

**Access grants:**
- Application billing service — read/write
- Financial auditors / CPAs — read-only, time-limited, specific table grants
- CFO and finance team — read-only dashboard access
- Tax service (Tax1099) — write for 1099 generation only
- Stripe webhooks — scoped write for payment event updates
- No access from any other application service domain

**Why isolated:**
- PCI DSS requires payment data in its own isolated environment with its own network controls
- Independent encryption — payment DB uses HSM-backed key separate from all others
- Breach reveals financial records but NOT user identity, lease terms, or property data
- Financial auditor access cleanly scoped — no other data visible
- 7-year IRS retention enforced at database level with legal hold integration

---

### DB 5: Wildlife & Field Operations

**Purpose:** Everything that happens out in the field — the platform's core data asset

**Security tier:** Standard with anonymization layer
**PostgreSQL instance:** High-write optimized (SSD, write-optimized configuration)
**Encryption key:** Key E

```
Tables:
├── harvest_logs             (species, date, GPS, weapon type, photos, notes, user_id)
├── fishing_harvest_logs     (species, weight, length, GPS, catch-and-release flag)
├── exotic_harvest_logs      (exotic species, trophy fees triggered, score)
├── game_sightings           (trail camera uploads, manual sighting entries, GPS)
├── trail_cameras            (camera registry — make, model, location per property)
├── trail_camera_images      (image metadata, AI species tags — files in Blob)
├── species_quotas           (per-species limits per property per season)
├── quota_utilization        (running harvest totals vs. quota)
├── season_calendar          (all 50 states, all species, all weapon types, bag limits)
├── season_calendar_history  (regulation change history — who changed, when)
├── cwd_zones                (CWD management zone polygons — references Geo DB)
├── cwd_acknowledgments      (member acknowledgment per zone per season)
├── cwd_harvest_flags        (harvests flagged for CWD sampling)
├── mldp_records             (MLDP applications, permits, expiry)
├── food_plot_records        (location, species planted, acreage, condition notes)
├── invasive_species_reports (feral hog, kudzu, cogon grass, aquatic invasives)
├── weather_correlation_log  (weather conditions at time of harvest events)
├── trophy_scores            (B&C, SCI score entries per harvest)
├── hunt_stories             (narrative hunt reports — text, photos, conditions)
├── harvest_photo_gallery    (shared harvest photos — visibility settings)
├── species_ai_tags          (AI-generated species tags per trail cam image)
├── club_work_day_log        (volunteer habitat work hours per member)
└── property_condition_log   (pre/post-lease property condition records)
```

**Access grants:**
- Application wildlife service — read/write
- Wildlife agency partners — read-only, property-level filtering, no user PII
- Research partners — read-only on anonymized view only
- Admin reporting service — read-only

**Why isolated:**
- This is the **monetizable data asset** for Module AG — separation ensures anonymization pipeline never mixes with PII
- Wildlife agencies granted read access here only — never touching financial or user data
- High write bursts during season openers — scales independently without affecting lease or billing performance
- Academic and research partner access scoped to this database with row-level filtering on anonymized views

---

### DB 6: Commerce & Marketplace

**Purpose:** All marketplace, auction, and booking activity

**Security tier:** Standard
**PostgreSQL instance:** Burst-capable server (auction activity spikes)
**Encryption key:** Key F

```
Tables:
├── marketplace_listings     (products and services for sale)
├── listing_custom_fields    (JSONB category-specific field values)
├── listing_photos           (product photo metadata)
├── seller_profiles          (seller reputation, payout config, settings)
├── orders                   (buyer purchase orders)
├── order_items              (line items per order)
├── order_fulfillment        (shipping details, tracking numbers, local pickup)
├── buyer_reviews            (post-purchase product and seller ratings)
├── seller_reviews           (seller aggregate ratings)
├── auction_listings         (auction-specific configuration)
├── auction_bids             (bid history — append-only, immutable)
├── auction_bid_holds        (Stripe pre-authorized holds per bid)
├── auction_extensions       (auto-extend events per auction)
├── auction_results          (closed auction outcomes)
├── watchlist_items          (hunters watching auctions or listings)
├── outfitter_profiles       (outfitter business info, license, insurance)
├── hunt_packages            (guided hunt package definitions)
├── package_availability     (outfitter calendar availability)
├── bookings                 (guided hunt bookings)
├── booking_addons           (add-on selections per booking)
├── trip_rosters             (hunters booked per outfitter trip)
├── consulting_bookings      (consulting service engagement records)
├── consulting_deliverables  (uploaded deliverables per engagement)
├── gift_vouchers            (lease and hunt gift voucher records)
├── saved_searches           (member saved search profiles)
├── listing_alerts           (alert subscriptions per saved search)
└── wishlists                (member saved marketplace items)
```

**Access grants:**
- Application commerce service — read/write
- Vendor/seller reporting portal — row-level security to own records only
- Admin marketplace management — full read/write
- Fraud detection service — read-only

**Why isolated:**
- Real-time auction bid engine is write-intensive — independent scaling without impacting lease operations
- Marketplace fraud detection runs independently without affecting OLTP performance
- Vendor access cleanly scoped via Row-Level Security — no cross-vendor data leakage
- A marketplace data breach does not expose lease terms, user identity, or financial records

---

### DB 7: Communications & Notifications

**Purpose:** All messaging, alerts, notification records, and support interactions

**Security tier:** Standard with message content sensitivity
**PostgreSQL instance:** High-write, time-series optimized
**Encryption key:** Key G

```
Tables:
├── message_threads          (conversation threads — typed by context)
├── messages                 (individual messages — encrypted content)
├── message_attachments      (file metadata attached to messages)
├── broadcast_messages       (property-wide or platform-wide announcements)
├── broadcast_recipients     (delivery tracking per recipient)
├── notification_log         (all sent notifications — channel, status, timestamp)
├── notification_templates   (system notification template content)
├── notification_preferences (per-user channel and frequency preferences)
├── push_subscriptions       (PWA web push subscription endpoints)
├── support_tickets          (help desk tickets)
├── ticket_replies           (support conversation thread)
├── ticket_attachments       (files attached to tickets)
├── ticket_sla_tracking      (response time tracking per ticket)
├── live_chat_sessions       (Intercom/Crisp session records)
├── email_campaign_log       (Klaviyo sync and campaign send records)
├── sms_log                  (Twilio outbound records — opt-out tracking)
├── weather_alert_log        (NOAA alerts delivered per property per event)
├── sos_event_log            (SOS events — GPS, timestamp, contacts alerted — permanent)
├── sos_acknowledgment_log   (Emergency contact acknowledgment records)
├── nps_responses            (Net Promoter Score survey responses)
└── csat_responses           (Customer Satisfaction survey responses)
```

**Access grants:**
- Application communications service — read/write
- Support staff portal — scoped read to ticket tables only
- Admin broadcast service — write to broadcast tables only
- Emergency alert service — write to SOS and weather alert tables
- CCPA deletion service — message content purge on deletion request

**Why isolated:**
- SOS logs are life-safety legal records — must survive even if other databases fail
- Support staff access cleanly scoped — no lease, financial, or wildlife data visible
- Notification volume is enormous — completely independent scaling
- GDPR/CCPA right-to-deletion can process message records without touching other databases

---

### DB 8: Analytics & Reporting

**Purpose:** Read-optimized reporting data — populated by ETL, never written to by application

**Security tier:** Standard — aggregated/anonymized data only
**PostgreSQL instance:** Read-optimized, columnar-friendly configuration
**Encryption key:** Key H

```
Tables:
├── daily_platform_metrics   (MRR, new users, active leases, GMV — daily snapshots)
├── property_performance     (conversion rate, renewal rate, occupancy per property)
├── funnel_snapshots         (application pipeline stage counts — daily)
├── revenue_snapshots        (revenue by stream, by period)
├── lease_cohort_data        (lease cohort retention calculations)
├── user_cohort_data         (user acquisition cohort retention)
├── harvest_aggregates       (anonymized harvest aggregations — county/species/season)
├── fishing_aggregates       (anonymized fishing harvest by water body type/species)
├── search_trend_data        (search term frequency, filter usage — weekly rollups)
├── ad_impression_log        (advertising impression and click events)
├── ad_campaign_metrics      (daily campaign performance rollups)
├── conversion_funnel_events (de-identified funnel events per session)
├── churn_signals            (behavioral churn prediction feature inputs)
├── pricing_comparables      (market pricing benchmark data — denormalized)
├── demand_heatmap_data      (aggregated geographic demand signals by county)
├── club_health_metrics      (dues compliance rates, member churn per club type)
├── outfitter_performance    (booking volume, cancellation rate, ratings rollups)
├── marketplace_metrics      (GMV, take rate, category breakdown)
├── support_metrics          (ticket volume, resolution time, CSAT rollups)
└── wildlife_agency_export   (pre-built agency-facing harvest summary tables)
```

**Access grants:**
- ETL service — write-only (scheduled jobs populating from other DBs)
- BI tools (Metabase, Tableau, or similar) — read-only
- Analyst team — read-only
- Platform operators — read-only dashboard
- Wildlife agency partners — scoped read to wildlife_agency_export table only
- No direct application write access ever

**Why isolated:**
- Heavy analytical queries (full table scans, complex aggregations) never impact production databases
- Analysts connect here — never to production systems
- Pre-joined, denormalized data avoids cross-database queries at report time
- Anonymized aggregates only — no PII, no financial details

---

### DB 9: Audit & Compliance

**Purpose:** Immutable records of everything that happened on the platform — append-only

**Security tier:** Maximum — database-level immutability enforced
**PostgreSQL instance:** Append-only enforced via PostgreSQL rules — no UPDATE or DELETE ever
**Encryption key:** Key I

```
Tables:
├── audit_log                (every data mutation — user, table, row_id, old_value, new_value, timestamp)
├── access_log               (every authenticated request — user, endpoint, method, IP, timestamp)
├── admin_action_log         (every admin action — impersonation, bulk operations, config changes)
├── admin_impersonation_log  (every impersonation session — who, as whom, duration, actions taken)
├── data_export_log          (every CCPA data export request and fulfillment)
├── data_deletion_log        (every right-to-deletion request, execution steps, completion)
├── consent_log              (every privacy policy and ToS acceptance — version, timestamp, IP)
├── ofac_screening_log       (every OFAC/AML screening — result, timestamp, transaction linked)
├── sar_records              (Suspicious Activity Reports — legal hold, never purged)
├── esignature_audit_trail   (every signing event — IP, device, geolocation, document hash)
├── document_access_log      (who accessed which document, when, from where)
├── payment_state_log        (every payment status transition — Stripe event linked)
├── lease_state_log          (every lease status transition — who triggered, when)
├── feature_flag_change_log  (every feature flag change — who, what, when, previous value)
├── pentest_findings         (penetration test results, severity, remediation status)
├── legal_hold_flags         (records under litigation hold — cross-database references)
├── breach_incident_log      (security incidents, response timeline, notifications sent)
├── data_retention_job_log   (automated purge job execution records — what was purged)
└── api_key_usage_log        (API key usage events — key hash, endpoint, timestamp)
```

**Access grants:**
- Dedicated audit writer service — INSERT only, no UPDATE or DELETE, ever
- Compliance auditors — read-only, time-limited credentials
- Legal team — read-only, on-demand for litigation support
- SOC 2 auditors — read-only to defined table subset
- No application service has UPDATE or DELETE privileges on this database

**Why isolated:**
- Database-level constraints enforce immutability — no application bug can alter history
- Completely separate from production — auditors get full picture without production access risk
- Legal hold records exclude affected rows from automated purge jobs platform-wide
- 10+ year retention — separate backup policy from all other databases

---

### DB 10: Incidents & Safety

**Purpose:** All safety-critical records — isolated for legal, liability, and insurance purposes

**Security tier:** High — legal liability records
**PostgreSQL instance:** Dedicated server with long-term retention configuration
**Encryption key:** Key J

```
Tables:
├── incidents                (all incident reports — typed, structured, timestamped)
├── incident_media           (photo/video metadata per incident — files in Blob)
├── incident_timeline        (status transitions per incident — immutable)
├── incident_parties         (involved parties per incident)
├── poaching_reports         (formal poaching documentation — warden-formatted)
├── trespass_log             (trespass incidents with repeat offender tracking)
├── sos_events               (SOS button activations — GPS, timestamp, contacts alerted)
├── sos_acknowledgments      (emergency contact response acknowledgments)
├── property_emergency_closures (emergency closed status events with reason)
├── insurance_policies       (stored insurance policy records per user per property)
├── insurance_claims         (claims initiated from incident records)
├── claim_timeline           (status transitions per insurance claim)
├── security_deposit_disputes(deposit deduction disputes — pre-DB 12 escalation)
├── dispute_records          (all formal platform disputes)
├── dispute_evidence         (documents submitted by each party)
├── dispute_timeline         (status transitions per dispute — immutable)
├── dispute_determinations   (final outcomes and financial settlements)
├── weather_alert_events     (NOAA alerts per property with delivery confirmation)
└── property_condition_reports(pre/post-lease condition reports with photos)
```

**Access grants:**
- Application safety service — read/write
- Insurance carrier partners — scoped read to own policyholder incidents only
- Legal team — read-only on demand for litigation support
- Law enforcement — scoped read to specific incident records on formal request
- Admin safety dashboard — read-only

**Why isolated:**
- Insurance company access cleanly scoped — carriers see only relevant incident records
- Legal discovery — entire database can be isolated for legal hold without impacting operations
- SOS logs are life-safety records — must survive even if other databases are offline
- Law enforcement access scoped here — no user PII, financial data, or lease terms visible

---

### DB 11: Documents & Media

**Purpose:** All file and document metadata — actual binaries live in Azure Blob Storage

**Security tier:** Standard with document access controls
**PostgreSQL instance:** Standard server
**Encryption key:** Key K

```
Tables:
├── documents                (all document records — type, owner_id, status, storage_path)
├── document_versions        (version history — each version is a separate record)
├── document_signatures      (signature event references per document version)
├── document_access_grants   (who has access to what document and why)
├── document_expiry_tracking (license expiry, insurance expiry, cert expiry)
├── lease_template_library   (template metadata and content — rich text)
├── lease_template_versions  (full version history per template)
├── template_merge_fields    (merge field definitions and defaults per template)
├── property_photo_metadata  (property listing photos — order, alt text, tags)
├── harvest_photo_metadata   (harvest log photos — GPS, species tag)
├── video_metadata           (video asset metadata — HLS manifests, thumbnail paths)
├── video_processing_jobs    (FFmpeg transcoding job status)
├── qr_code_registry         (QR code assignments — property, stand, equipment)
├── qr_scan_log              (QR code scan events — timestamp, user, GPS)
├── print_job_log            (generated print document records)
├── bulk_import_jobs         (import job definitions, status, error logs)
├── bulk_import_rows         (per-row import results)
└── file_scan_results        (virus scan results per upload — every file)
```

**Access grants:**
- Application document service — read/write
- e-Signature service (Dropbox Sign) — scoped read for template content
- Video processing service — read/write for processing jobs
- Azure Blob Storage integration — storage path references only
- Document access service — validates grants before generating signed URLs

**Why isolated:**
- Document access control is independent of lease status — terminated leases still require 7-year document retention
- e-Signature provider references documents by ID — this DB is the immutable source of truth
- Video transcoding is high CPU/IO — isolated to prevent impact on OLTP databases
- File scan results tied to every upload — security audit trail independent of content

---

### DB 12: Platform Configuration & Operations

**Purpose:** How the platform is configured — not what users do

**Security tier:** Standard — admin-only write access
**PostgreSQL instance:** Standard server — aggressively Valkey-cached
**Encryption key:** Key L

```
Tables:
├── feature_flags            (flag definitions, rules, percentage rollouts)
├── feature_flag_overrides   (per-user and per-tenant flag overrides)
├── system_settings          (platform-wide configuration key/value)
├── tenant_settings          (per-white-label-tenant configuration)
├── tenant_branding          (logos, color schemes, custom domains)
├── automation_workflows     (visual workflow definitions — JSON DAG)
├── automation_triggers      (trigger definitions per workflow)
├── automation_actions       (action definitions per workflow)
├── automation_run_log       (workflow execution records)
├── state_regulations        (season calendar, bag limits, weapon types per state)
├── state_regulation_history (previous regulation versions)
├── cwd_zone_definitions     (CWD zone polygon references — links to Geo DB)
├── game_warden_directory    (county-level warden contacts — all 50 states)
├── insurance_carrier_config (carrier API configurations — encrypted credentials)
├── pricing_benchmarks       (market comparables data — updated by pricing service)
├── demand_heatmap_config    (geographic demand configuration)
├── affiliate_program_config (commission structures, payout rules)
├── ad_placement_definitions (ad slot definitions — sizes, locations)
├── ad_campaigns             (advertiser campaign configurations)
├── ad_creatives             (uploaded ad creative metadata)
├── maintenance_windows      (scheduled maintenance records)
└── platform_health_sla      (SLA tier definitions and current uptime metrics)
```

**Access grants:**
- Application config service — read-only (aggressively cached in Valkey)
- Admin staff — write access to regulated tables (regulations, warden directory, feature flags)
- Automation service — read workflow definitions, write run log
- Advertiser portal — scoped write to own campaign tables only

**Why isolated:**
- Configuration data changes infrequently — aggressive Valkey caching means near-zero DB reads at runtime
- Regulation updates made by admin staff without risk of impacting user data
- White-label tenant configurations isolated — no cross-tenant configuration leakage possible
- Feature flag changes audit-logged in DB 9 independently

---

### DB 13: Geospatial

**Purpose:** All spatial/geographic data — PostGIS operations isolated from all other workloads

**Security tier:** Standard
**PostgreSQL instance:** Dedicated PostGIS-optimized server — high memory, SSD, PostGIS 3.x
**Encryption key:** Key M
**Extensions:** PostGIS 3.x, pgRouting (optional), PostGIS Raster (optional)

```
Tables:
├── property_boundaries      (PostGIS Polygon/MultiPolygon — parcel boundaries)
├── property_centroids       (PostGIS Point — derived centroid per property)
├── stand_locations          (PostGIS Point — stand/blind/feeder GPS positions)
├── water_body_geometries    (PostGIS Polygon — pond/lake/wetland boundaries)
├── trail_geometries         (PostGIS LineString — road/trail paths)
├── camp_locations           (PostGIS Point — camp structure GPS positions)
├── harvest_locations        (PostGIS Point — GPS per harvest log entry)
├── sighting_locations       (PostGIS Point — game sighting GPS pins)
├── camera_locations         (PostGIS Point — trail camera GPS positions)
├── sos_locations            (PostGIS Point — SOS event GPS — high-security read)
├── incident_locations       (PostGIS Point — incident report GPS positions)
├── fema_flood_zones         (PostGIS MultiPolygon — FEMA flood zone layers)
├── wetland_delineations     (PostGIS MultiPolygon — USFWS NWI data)
├── cwd_zone_polygons        (PostGIS MultiPolygon — CWD management zones)
├── critical_habitat_zones   (PostGIS MultiPolygon — USFWS critical habitat)
├── conservation_easements   (PostGIS Polygon — easement boundaries)
├── county_boundaries        (PostGIS MultiPolygon — all US counties)
├── state_boundaries         (PostGIS MultiPolygon — all US states)
├── parcel_data              (PostGIS Polygon — OnX/PLSS parcel imports)
├── usgs_topo_tiles          (PostGIS Raster — topographic tile metadata)
├── soil_survey_polygons     (PostGIS MultiPolygon — USDA Web Soil Survey)
├── timber_stand_polygons    (PostGIS MultiPolygon — timber stand areas)
├── cellular_coverage_grid   (PostGIS MultiPolygon — carrier coverage areas)
├── wildfire_perimeters      (PostGIS MultiPolygon — USFS active fire perimeters — live feed)
├── demand_heatmap_cells     (PostGIS Polygon — hex grid demand visualization)
└── geo_search_cache         (PostGIS — cached radius search results — TTL managed)
```

**Spatial indexes — all geometry columns:**
```sql
-- GiST indexes on all geometry columns for fast spatial queries
CREATE INDEX idx_property_boundaries_geom ON property_boundaries USING GIST (geom);
CREATE INDEX idx_harvest_locations_geom ON harvest_locations USING GIST (geom);
CREATE INDEX idx_fema_flood_zones_geom ON fema_flood_zones USING GIST (geom);
-- etc. for every geometry column
```

**Key spatial queries this database powers:**
```sql
-- Find all properties within 50 miles of Dallas
SELECT property_id FROM property_centroids
WHERE ST_DWithin(
    geom::geography,
    ST_MakePoint(-96.7970, 32.7767)::geography,
    80467  -- meters
);

-- Check if a harvest GPS point falls within a CWD zone
SELECT cwd_zone_id, zone_name, restrictions
FROM cwd_zone_polygons
WHERE ST_Contains(geom, ST_MakePoint($longitude, $latitude));

-- Find all properties overlapping a FEMA 100-year flood zone
SELECT pb.property_id
FROM property_boundaries pb
JOIN fema_flood_zones fz ON ST_Intersects(pb.geom, fz.geom)
WHERE fz.flood_zone_type = '100_year';

-- Calculate property boundary area in acres
SELECT property_id,
       ST_Area(geom::geography) / 4046.86 AS calculated_acres
FROM property_boundaries;
```

**Access grants:**
- Application geospatial service — read/write for property and user-generated geometry
- Admin boundary editor — write for property_boundaries, stand_locations
- Public listing API — read-only on property_centroids and property_boundaries (published only)
- FEMA/USFWS data sync service — write to regulatory layer tables only
- Wildfire alert service — write to wildfire_perimeters (live feed)
- Analytics service — read for demand heatmap generation

**Why dedicated:**
- PostGIS spatial indexes (GiST) are memory and CPU intensive — completely different resource profile from OLTP
- Spatial queries on large datasets (all US parcels, FEMA flood zones) would destroy performance on shared servers
- Large geometry storage (parcel polygons, wetland delineations) benefits from separate I/O allocation
- Raster data (USGS topo tiles) requires PostGIS Raster extension — specialized server config
- OnX and PLSS parcel data imports can be terabytes — isolated storage budget
- Wildfire perimeter live feeds update frequently — isolated write pressure
- Mapbox serves tiles built from this data — high read throughput for map tile generation

**Data pipeline:**
```
OnX API → Parcel import job → property_boundaries (Geo DB)
FEMA API → Sync job → fema_flood_zones (Geo DB)
USFWS API → Sync job → wetland_delineations + critical_habitat_zones (Geo DB)
USFS API → Live feed → wildfire_perimeters (Geo DB) → NOAA alert service
USDA API → Sync job → soil_survey_polygons (Geo DB)
User draws boundary → Admin UI → property_boundaries (Geo DB)
Harvest log submit → GPS extracted → harvest_locations (Geo DB)
```

---

### DB 14: Research & Data Monetization

**Purpose:** Anonymized harvest dataset — air-gapped from all production systems

**Security tier:** Air-gapped — no application connection, one-way ETL only
**PostgreSQL instance:** Completely separate server — no network route to production
**Encryption key:** Key N — managed separately from all production keys

```
Tables:
├── anonymized_harvests      (cohort_id, species, county_fips, state, weapon_type, harvest_date, moon_phase, temp_range, precipitation)
├── anonymized_fishing       (cohort_id, species, water_body_type, county_fips, state, catch_date, release_flag, weight_range)
├── anonymized_sightings     (cohort_id, species, county_fips, state, sighting_date, camera_flag)
├── county_harvest_summary   (county_fips, state, species, season_year, total_harvest, weapon_breakdown)
├── state_harvest_trends     (state, species, season_year, total_harvest, yoy_change)
├── species_activity_patterns(species, month, moon_phase_range, temp_range, relative_activity_index)
├── habitat_harvest_correlation (habitat_type, species, relative_harvest_success)
├── pricing_market_data      (state, county_fips, lease_type, acreage_range, price_percentiles — no property IDs)
├── demand_signals           (state, county_fips, species, search_volume_index, inquiry_volume_index)
├── researcher_queries       (logged queries by licensed researchers — for data governance)
├── data_license_agreements  (licensed researcher and organization records)
└── data_access_log          (every query executed by licensed researchers)
```

**One-way ETL pipeline:**
```
Wildlife DB (DB 5) → Anonymization job → Research DB (DB 14)

Anonymization rules:
- user_id → cohort_id (irreversible hash with salt)
- Precise GPS → county_fips (generalized to county level)
- Exact date → season_week (rounded to week within season)
- Property_id → habitat_type + acreage_range (generalized)
- Weapon type → kept as-is (not PII)
- Species → kept as-is
```

**Access grants:**
- ETL anonymization service — INSERT only, one-way, no read-back to production
- Licensed wildlife agency researchers — read-only via dedicated VPN + credentials
- Licensed academic researchers — read-only via researcher portal (query interface only)
- Licensed commercial researchers — scoped read to purchased dataset segments
- Platform data science team — read-only for model development
- Zero production application connections — ever

**Why air-gapped:**
- If this database is breached, zero PII is exposed — anonymization is irreversible
- Research partners never get a network path to production systems
- Regulatory requirement for wildlife data sharing agreements with state agencies
- Data monetization contracts require provable PII separation
- Enables GDPR/CCPA-compliant data licensing — individual users cannot be re-identified

---

### Valkey Cluster Architecture

Five independent Valkey clusters — separated by security and functional domain:

| Cluster | Contents | TTL Policy |
|---|---|---|
| **Valkey 1 — Sessions** | User session tokens, MFA state | Session timeout — 24hr default |
| **Valkey 2 — App Cache** | Property listings, lease summaries, config | 5min–1hr depending on data type |
| **Valkey 3 — Queue Jobs** | Notification jobs, video processing, ETL, 1099 generation | Until processed |
| **Valkey 4 — Auction State** | Live bid state, countdown timers, bid lock | Auction lifetime |
| **Valkey 5 — Rate Limiting** | Per-user API rate limit counters | Rolling 1hr window |

Each cluster on separate Valkey instances — a Valkey failure in one domain does not affect others. Session Valkey failure causes re-authentication; Auction Valkey failure pauses bidding — neither affects billing or lease operations.

---

### Complete Database Map

| # | Database | Server Tier | Encryption Key | Retention | Third-Party Access |
|---|---|---|---|---|---|
| 1 | Identity & Authentication | High-security dedicated | Key A | 7yr post-deletion | None |
| 2 | Property & Land | High-memory standard | Key B | Indefinite (active) + 7yr archive | Wildlife agencies (read) |
| 3 | Lease & Contract | High-security dedicated | Key C | 7yr minimum | Legal auditors (read) |
| 4 | Billing & Payments | PCI-compliant dedicated | Key D (HSM) | 7yr IRS requirement | CPAs, financial auditors (read) |
| 5 | Wildlife & Field | High-write optimized | Key E | Indefinite — data asset | Wildlife agencies, researchers (anonymized read) |
| 6 | Commerce & Marketplace | Burst-capable standard | Key F | 7yr post-transaction | Vendors (own records only) |
| 7 | Communications | High-write time-series | Key G | 2yr active + 5yr archive | Support staff (tickets only) |
| 8 | Analytics & Reporting | Read-optimized columnar | Key H | 5yr rolling | BI tools, analysts, agencies (wildlife_agency_export only) |
| 9 | Audit & Compliance | Append-only dedicated | Key I | 10yr minimum | SOC 2 auditors, legal team (read) |
| 10 | Incidents & Safety | High-security dedicated | Key J | Indefinite — legal liability | Insurance carriers (scoped), law enforcement (on demand) |
| 11 | Documents & Media | Standard | Key K | 7yr minimum | e-Signature service (scoped) |
| 12 | Platform Config | Standard (cached) | Key L | Indefinite | None |
| 13 | Geospatial | PostGIS dedicated | Key M | Indefinite | Public listing API (read), Mapbox (read) |
| 14 | Research Dataset | Air-gapped dedicated | Key N | Indefinite — revenue asset | Licensed researchers, wildlife agencies (scoped read) |
| R1 | Valkey — Sessions | In-memory | TLS in-transit | Session TTL | None |
| R2 | Valkey — App Cache | In-memory | TLS in-transit | Cache TTL | None |
| R3 | Valkey — Job Queue | In-memory | TLS in-transit | Until processed | None |
| R4 | Valkey — Auction State | In-memory | TLS in-transit | Auction lifetime | None |
| R5 | Valkey — Rate Limits | In-memory | TLS in-transit | 1hr rolling | None |

---

### Cross-Database Query Pattern

The standard service-layer assembly pattern used throughout the application:

```php
// Every service assembles its composite DTO from multiple databases
// Individual lookups are Valkey-cached — cross-DB overhead is negligible

class PropertyListingService
{
    public function getPublicListing(string $propertyId): PublicListingDTO
    {
        // DB 2 — property core data
        $property = Cache::remember("property:{$propertyId}", 300, fn() =>
            Property::on('property')->with(['photos', 'pricing'])->findOrFail($propertyId)
        );

        // DB 13 — geospatial boundary
        $boundary = Cache::remember("boundary:{$propertyId}", 3600, fn() =>
            PropertyBoundary::on('geospatial')->where('property_id', $propertyId)->first()
        );

        // DB 6 — active auction if exists
        $auction = AuctionListing::on('commerce')
            ->where('property_id', $propertyId)
            ->where('status', 'active')
            ->first();

        return new PublicListingDTO($property, $boundary, $auction);
    }
}
```

---

### Backup Strategy Per Database

| Database | Backup Frequency | Point-in-Time Recovery | Retention | Off-Site |
|---|---|---|---|---|
| Identity | Continuous WAL + daily dump | 5-minute window | 7 years | Yes — encrypted |
| Property | Continuous WAL + daily dump | 5-minute window | Indefinite | Yes |
| Lease | Continuous WAL + daily dump | 5-minute window | 7 years | Yes — encrypted |
| Billing | Continuous WAL + hourly dump | 1-minute window | 7 years | Yes — HSM encrypted |
| Wildlife | Continuous WAL + daily dump | 5-minute window | Indefinite | Yes |
| Commerce | Continuous WAL + daily dump | 5-minute window | 7 years | Yes |
| Communications | Daily dump | None (acceptable) | 2 years active | Yes |
| Analytics | Daily dump | None (re-derivable) | 5 years | Yes |
| Audit | Continuous WAL + daily dump | 5-minute window | 10 years | Yes — encrypted |
| Incidents | Continuous WAL + daily dump | 5-minute window | Indefinite | Yes — encrypted |
| Documents | Daily dump | None | 7 years | Yes |
| Platform Config | Daily dump | None | 5 years | Yes |
| Geospatial | Weekly dump + on-change | None | Indefinite | Yes |
| Research | Weekly dump | None | Indefinite | Yes — air-gapped |

---

---

## Final Scope Additions — Legal, Operational & Infrastructure

---

### Module BJ: Recreational Use Statute & Landowner Liability Compliance

**Purpose:** Ensure every lease explicitly invokes applicable state RUS protections — the most critical legal safeguard for every landowner on the platform.

| Feature | Details |
|---|---|
| RUS clause library | State-specific Recreational Use Statute language for all 50 states |
| Auto-invocation | Correct RUS clause automatically included in lease template based on property state |
| RUS eligibility analysis | Flag states where paid leases may reduce RUS protection — attorney-reviewed notes |
| Assumption of risk clauses | State-specific language — hunters explicitly assume inherent risks |
| Negligence standard notation | Gross vs. ordinary negligence distinctions per state — reflected in templates |
| Third-party visitor liability | Explicit clause defining lessee responsibility for guests |
| Platform liability disclaimer | Platform is a technology intermediary — not a party to lease, not responsible for property conditions, not an insurer — on every user-facing surface |
| Annual RUS review | Attorney review of all RUS clauses annually or when state law changes |
| Lessee acknowledgment | Hunters explicitly acknowledge assumption of risk and RUS terms at signing |

---

### Module BK: Multi-Species Lease Segmentation

**Purpose:** Model simultaneous multiple lessee groups on one property — extremely common real-world scenario.

| Feature | Details |
|---|---|
| Species-segmented lease types | One property supports deer + duck + fishing leases simultaneously |
| Zone-based access mapping | Spatial zones defined per lease — deer hunters get upland, duck hunters get wetlands |
| Season conflict matrix | Visual conflict grid — which group has priority in which zone on which dates |
| Multi-lessee revenue stacking | Landowner earns from multiple concurrent leases on same acreage |
| Cross-lessee rules | What each group is prohibited from doing in shared or adjacent zones |
| Simultaneous scheduling | Scheduling engine enforces zone-based access — no conflicts |
| Combined property map | Each lessee group sees their zone clearly differentiated |
| Zone-specific stand registry | Stands assigned to correct lessee zone |
| Revenue reporting per segment | Revenue attributed per species-lease segment |

---

### Module BL: Lease Pause & Suspension Workflow

**Purpose:** Temporary lease interruptions — distinct from termination — preserving the relationship through hardship.

| Feature | Details |
|---|---|
| Medical hardship pause | Documentation upload, admin approval, payments and/or access suspended |
| Military deployment pause | Formal deployment document — auto-pause — integrates with Module AX |
| Family emergency pause | Admin-approved temporary suspension |
| Natural disaster pause | Property emergency closure auto-triggers lease pause |
| Landowner-initiated pause | Property temporarily inaccessible — agricultural, remediation, construction |
| Pause duration | Fixed or open-ended — maximum configurable per lease type |
| Lease term extension | Configurable — term extends by pause duration or not |
| Prorated payment handling | Pause suspends billing — credits or extends on resume |
| Partial pause | Payments pause but access continues — or vice versa |
| Pause audit trail | Who initiated, reason, documentation, start/end timestamps |
| Auto-resume | Configurable — auto-resume after defined period or require manual re-activation |

---

### Module BM: Sublease & Co-Lessee Models

**Purpose:** Two common real-world lease structures outside the single-lessee and club models.

### Sublease
| Feature | Details |
|---|---|
| Sublease request | Primary lessee requests landowner approval through platform |
| Landowner approval | Approve, deny, or counter-propose sublease terms |
| Sub-lessee onboarding | Full verification, waiver, license upload |
| Sub-agreement generation | Separate sub-lease document — primary lessee as sublessor |
| Primary lessee liability retention | Primary lessee remains responsible for all sub-lessee actions |
| Sublease processing fee | Platform or landowner configurable |

### Co-Lessee Joint Lease
| Feature | Details |
|---|---|
| Co-lessee registration | 2–4 individuals jointly apply |
| Individual compliance | Each co-lessee completes verification independently |
| Shared payment responsibility | Split invoicing — each pays configured portion |
| Joint and several liability | All co-lessees responsible for any individual's violations |
| Co-lessee removal | One partner exits — remaining assume full obligation |
| Co-lessee addition | Add new co-lessee mid-term with landowner approval |

---

### Module BN: Chargeback & Payment Dispute Management

**Purpose:** Protect revenue from fraudulent chargebacks on large lease payments.

| Feature | Details |
|---|---|
| Stripe chargeback detection | Webhook-triggered workflow on chargeback filed |
| Evidence auto-package | Signed lease, payment history, access logs, check-in records, communications, IP logs |
| Evidence submission workflow | Admin reviews, adds notes, submits to Stripe Dispute portal |
| Response deadline tracking | Stripe dispute windows strict — PagerDuty alert at 3 days before deadline |
| Win/loss tracking | Outcomes per user — patterns flagged |
| ACH return handling | NSF returns, unauthorized ACH claims — separate workflow |
| High-risk transaction flags | New account + large payment — additional verification before processing |
| Chargeback analytics | Rate by payment method, lease type, user tenure |

---

### Module BO: E-911 Address & What3Words Integration

**Purpose:** Dispatcher-ready location data for every property — life-critical for remote SOS.

| Feature | Details |
|---|---|
| E-911 address field | Separate from mailing address — actual emergency services routing address |
| Dispatcher notes | "Enter from FM 979, gate is 2.3 miles past railroad tracks" |
| What3Words integration | Three-word codes for every GPS point — stands, camps, harvest locations |
| SOS alert format | County, intersection, GPS, property name, landowner phone, What3Words, dispatcher notes |
| County PSAP database | Per-county 911 center phone numbers — SOS guidance displays correct center |
| Warden verification display | Game warden confirms active lease without requiring member to unlock phone |
| Rural routing notes | Known GPS routing errors per property flagged and documented |

---

### Module BP: DMCA Compliance

**Purpose:** Legal safe harbor protection for user-generated content.

| Feature | Details |
|---|---|
| DMCA designated agent | Registered with US Copyright Office — legally required for safe harbor |
| Takedown request intake | Structured form — complainant info, work identified, infringing URL |
| Takedown response | Content removed/disabled within 24 hours of valid notice |
| Counter-notice workflow | User disputes takedown — formal 10-14 day process |
| Repeat infringer policy | Three strikes account termination — required for safe harbor |
| Takedown log | Every request, action, counter-notice — permanent record |
| Automated hash screening | Known copyrighted content detected on upload |

---

### Module BQ: Platform Insurance & Legal Structure

**Purpose:** Protect the platform entity from the liabilities it creates.

| Feature | Details |
|---|---|
| Cyber liability insurance | Data breach notification, fines, credit monitoring, legal defense |
| Errors & Omissions (E&O) | Claims platform services caused financial harm |
| Directors & Officers (D&O) | Executive personal liability protection |
| General liability | Physical incidents at platform-hosted events |
| Platform intermediary disclaimers | Technology intermediary language on every user-facing surface |
| Terms of service liability caps | Per-incident cap, no consequential damages |
| Governing law & jurisdiction | ToS specifies governing state and dispute venue |

---

### Module BR: Staging, UAT & Demo Environment Architecture

**Purpose:** Safe, repeatable deployment pipeline with full environment parity across all 14 databases.

| Environment | Purpose | Data |
|---|---|---|
| Local Development | Developer machines — Docker Compose | Seeded synthetic data |
| CI | GitHub Actions automated tests | Fresh synthetic per run |
| Staging | Pre-production — production replica | Anonymized production snapshot |
| UAT | Client acceptance testing | Curated test scenarios |
| Demo | Sales demos | Curated synthetic — resets nightly |
| Production | Live platform | Real data |

| Feature | Details |
|---|---|
| Docker Compose dev setup | All 14 PostgreSQL databases + 5 Valkey clusters — one command startup |
| Anonymized production snapshot | Weekly anonymized copy pushed to staging — PII scrubbed |
| Seed data system | Realistic synthetic data generator — properties, leases, users, harvest data |
| Environment promotion gates | dev → CI → staging → UAT → production with explicit approvals |
| Database migration coordination | Ordering and rollback strategy across all 14 databases |
| Feature branch environments | Ephemeral environments per PR — auto-provisioned and destroyed on merge |
| Demo environment reset | Nightly automated reset — sales team always has clean state |
| Smoke test suite | Post-deploy critical path verification before traffic shifted |

---

### Module BS: Database Infrastructure — PgBouncer, Read Replicas & Monitoring

**Purpose:** Production-grade database infrastructure for 14 PostgreSQL instances.

### PgBouncer Connection Pooling
| Feature | Details |
|---|---|
| PgBouncer per database | Transaction pooling mode in front of every PostgreSQL instance |
| Pool sizing | Configured per database load profile |
| PgBouncer monitoring | Pool utilization, wait time, errors — Datadog integration |

### Read Replica Strategy
| Database | Replicas | Reason |
|---|---|---|
| Property DB (DB 2) | 2 replicas | High read from public listing pages |
| Wildlife DB (DB 5) | 1 replica | Reporting separated from write path |
| Analytics DB (DB 8) | 1 replica | BI tool queries isolated from ETL writes |
| Geospatial DB (DB 13) | 1 replica | Map tile reads separated from boundary writes |
| All others | Primary only | Volume or sensitivity doesn't justify replication |

### Database Monitoring
| Feature | Details |
|---|---|
| pg_stat_statements | Query performance visibility on all instances |
| Slow query logging | Queries over 100ms logged — weekly review |
| Replication lag monitoring | Alert when replica > 30 seconds behind |
| Connection count alerts | Alert when approaching max_connections |
| Per-database Datadog integration | Query volume, lock waits, cache hit ratio |

### Database User/Role Architecture
Per database — six named users:
- **app_user** — application read/write
- **readonly_user** — reporting, analytics, partner access
- **backup_user** — pg_dump only
- **migration_user** — DDL changes during deployments
- **audit_writer** — INSERT only on DB 9
- **monitor_user** — pg_stat_statements read only

### Cross-Database Consistency
| Feature | Details |
|---|---|
| Orphan detection jobs | Scheduled verification of referential integrity across databases |
| Reconciliation reports | Weekly reconciliation across key cross-database relationships |
| Schema versioning | Each database has its own independent migration version table |
| Soft deletes everywhere | deleted_at timestamps — preserves cross-database references |

---

### Module BT: Regulatory Compliance Additions

### Baiting & Feed Regulations
| Feature | Details |
|---|---|
| State baiting law database | Legality per state with season-specific rules |
| CWD zone baiting interaction | Feeder registry cross-references CWD zones — flags illegal bait |
| Baiting removal reminders | Automated alert when bait must be removed before season |
| Lease rules acknowledgment | Lessee acknowledges baiting rules for property state |

### Drone & Trail Camera Regulations
| Feature | Details |
|---|---|
| State drone scouting ban database | States prohibiting drone use to locate game — displayed per property |
| Cellular camera restriction database | MT, NM, NV, ID, CO and growing — non-compliant cameras flagged |
| Camera ID tag generator | Printable owner identification tags |
| FAA Part 107 notice | Commercial drone use notice for property tour filming |

### Sunday Hunting Laws
| Feature | Details |
|---|---|
| Sunday restriction database | DE, MD, NJ, NC, PA, VA — county-level where applicable |
| Scheduling enforcement | Prevent hunt booking on restricted Sundays |
| Annual update workflow | Sunday ban status reviewed annually |

### Lead Ammunition Restrictions
| Feature | Details |
|---|---|
| California lead ban display | All California properties display statewide prohibition |
| Waterfowl non-toxic requirement | Federal non-toxic shot displayed on all duck/goose properties |
| Compliance acknowledgment | Ammunition acknowledgment in scheduling for affected properties |

---

### Module BU: Member Digital ID & Lease Pass

| Feature | Details |
|---|---|
| Digital membership card | Member name, property, lease period, member number, QR — mobile-displayable |
| Lease pass | Printable active lease status document for law enforcement |
| Guest pass digital card | Time-limited card texted/emailed to registered guest |
| Warden verification QR | Links to public verification page — no phone unlock required |
| Expired visual state | Card clearly shows expired status |
| Apple Wallet / Google Wallet | Add to phone wallet — available offline |

---

### Module BV: Lease Assignment & Transfer

| Feature | Details |
|---|---|
| Assignment request | Current lessee initiates — includes proposed assignee |
| Landowner approval | Review assignee profile and trust score |
| Assignment processing fee | Platform or landowner configurable |
| New lessee onboarding | Verification, documents, remaining balance payment |
| Original lessee release | Formally released from obligations on completion |
| Partial club assignment | Transfer one member slot mid-season |
| Assignment audit trail | Full record of all requests and outcomes |

---

### Module BW: Landowner Response Metrics

| Feature | Details |
|---|---|
| Inquiry response time | Median response time — displayed on listing |
| Application acceptance rate | Percentage accepted — displayed publicly |
| Response rate badge | "Responds within 24 hours" trust badge |
| Last active indicator | When landowner was last active on platform |
| Average renewal rate | Lessee renewal percentage per property |
| Search ranking impact | Slow-responding landowners ranked lower in results |

---

### Module BX: Two-Person Authorization for Critical Actions

| Feature | Details |
|---|---|
| Dual approval triggers | Bulk user deletion, large financial adjustments, legal hold removal, 1099 batch filing, mass lease termination |
| Approver notification | Full context — action, initiator, timestamp |
| Approval timeout | Expires after X hours — must be re-initiated |
| Approval audit trail | Both initiator and approver logged in Audit DB |
| Break-glass override | Emergency bypass — mandatory explanation, executive notification |

---

### Module BY: Infrastructure as Code & Container Architecture

| Feature | Details |
|---|---|
| Terraform / Pulumi | All Azure infrastructure defined as code |
| Docker | All application services containerized |
| Azure Container Apps | Container orchestration — horizontal scaling per service |
| Container image scanning | Trivy or Snyk in CI/CD |
| Software Bill of Materials | SBOM per release — supply chain security |
| Zero-downtime deployment | Blue/green — traffic shifted after health checks pass |
| Database migration pipeline | Coordinated execution across 14 databases — ordered, validated, rollback-capable |
| Infrastructure drift detection | Terraform plan nightly — alert on manual changes |

---

### Module BZ: Lease Wanted / Reverse Marketplace

| Feature | Details |
|---|---|
| Lease wanted board | Hunters post specifications — state, county, acreage, species, budget, dates |
| Landowner browse | Filter hunter requests by property state/county |
| Landowner alert | Property listed matching active wanted post — both parties notified |
| Request-to-inquiry | Landowner initiates inquiry to hunter from wanted post |
| Request expiry | Wanted posts expire after configurable window |
| Request analytics | Aggregate demand by state, species, price range — feeds pricing intelligence |

---

### Module CA: Equipment Rental

| Feature | Details |
|---|---|
| Rental listings | Trail cameras, feeders, stands, blinds — by season or week |
| Rental calendar | Availability per item |
| Damage deposit | Collected at checkout — returned on confirmed good return condition |
| Return condition workflow | Renter and owner document return condition with photos |
| Late fee configuration | Per-day fees configurable per listing |
| Rental insurance | Equipment damage coverage at checkout |
| Inventory management | Location, current renter, return date tracking |

---

### Module CB: Wildlife Population Modeling Tools

| Feature | Details |
|---|---|
| Doe-to-buck ratio calculator | Input sightings and harvest data — ratio estimate |
| Age structure analysis | Categorize buck harvest by estimated age class |
| Population trend graph | Year-over-year sighting and harvest trends per property |
| Herd management goals | Landowner/consultant sets target ratios and age structure |
| Management recommendations | Current data vs. goals — harvest adjustment suggestions |
| Camera grid density calculator | Recommended cameras per acre for accurate estimates |
| Harvest pressure analysis | Days hunted vs. sightings ratio |

---

### Module CC: Property Embed Widget

| Feature | Details |
|---|---|
| Embeddable listing card | JavaScript snippet for landowner's own website |
| Embed display | Photo, key stats, availability, inquiry button |
| Inquiry capture | Embedded inquiries routed into platform pipeline |
| Embed analytics | Impressions and clicks tracked separately |
| Custom embed styling | Color and font configuration |
| Embed authentication | Signed tokens — prevent unauthorized embedding |

---

### Module CD: Trophy Scoring Tools

| Feature | Details |
|---|---|
| B&C scoring calculator | Interactive Boone & Crockett — each measurement with visual guide |
| Pope & Young scoring | Archery-specific calculator |
| SCI scoring calculator | Safari Club International — exotic and international species |
| Photo-based estimate | AI-assisted gross score estimate from photo |
| Score history per property | Aggregated trophy quality trend over seasons |
| Score leaderboard | Top scores on listing — outfitter booking conversion tool |
| Score certificates | Printable score certificate per harvest entry |

---

### Module CE: Property Open House & Trial Hunt

| Feature | Details |
|---|---|
| Open house event creation | Landowner schedules structured property visit |
| Prospect registration | Hunters register — landowner approves attendees |
| Attendee cap | Maximum attendees per event |
| Trial hunt option | Hosted hunt day for serious prospects before committing |
| Post-visit application | Visited prospects apply with "met landowner" badge |
| Open house analytics | Events to applications conversion tracking |

---

### Module CF: Club Expense Sharing & Capital Improvement

| Feature | Details |
|---|---|
| Club expense categories | Food plot seed, feeder corn, road maintenance, stands, gate repair, lease fee |
| Expense receipt upload | Officer uploads receipt — stored against expense record |
| Split calculation | Equal, by membership tier, or custom allocation |
| Member reimbursement | Member pays personally — submits for club reimbursement |
| Annual club P&L | Income vs. expenses — shareable with membership |
| Capital improvement classification | Distinguish recurring operating from capital improvements |
| Property improvement credit | Landowner credits club improvements against renewal price |

---

### Module CG: Synthetic Monitoring & Load Testing

### Synthetic Monitoring
| Feature | Details |
|---|---|
| Critical journey monitors | Continuously simulate: list property, submit application, process payment, log harvest, submit SOS, place bid |
| Performance budgets | Public listing < 200ms, lease signing < 500ms, payment < 2s, map load < 1s |
| Geographic monitoring | Monitors from multiple US regions — detect regional latency |
| Alert on breach | PagerDuty when any critical journey exceeds budget |

### Load Testing
| Profile | Target |
|---|---|
| Normal day | Baseline concurrent users |
| Season opener spike | 10x normal — first day of deer season |
| Auction close event | 100x normal for auction endpoints |
| Database stress | Each of 14 databases independently load tested |

### Chaos Engineering
| Feature | Details |
|---|---|
| Database failover | Kill one database — verify graceful degradation |
| Valkey failure simulation | Verify application degrades gracefully without cache |
| Queue failure simulation | Verify jobs queue properly when Valkey unavailable |
| Dependency timeout simulation | Third-party API timeouts — verify fallback behavior |
| Results documented | Know exactly what breaks before users do |

---

## Final Complete Module Index

| ID | Module | Category |
|---|---|---|
| — | Public Frontend | Core Portal |
| — | Customer Portal | Core Portal |
| — | Member Portal | Core Portal |
| — | Admin Backend | Core Portal |
| — | Reporting Suite | Core Portal |
| A | Auction-Based Lease Bidding | Monetization |
| B | Habitat & Wildlife Consulting Marketplace | Services |
| C | Outfitter & Guide Booking | Services |
| D | Equipment & Services Marketplace | Commerce |
| E | Hunting Club / Group Lease Management | Operations |
| F | Legal & Compliance Architecture | Legal |
| G | Incident & Emergency Management | Safety |
| H | Financial & Tax Compliance | Finance |
| I | Regulatory & Wildlife Compliance | Compliance |
| J | Marketing & Growth Tools | Growth |
| K | Customer Support Infrastructure | Operations |
| L | Pricing Intelligence & Market Tools | Intelligence |
| M | Platform Integrations | Integrations |
| N | Multi-Tenancy & White Label | Scale |
| O | Waitlist Management | Conversion |
| P | Lease Negotiation Workflow | Operations |
| Q | Property Tour & Virtual Walkthrough | Discovery |
| R | Gamification & Community | Retention |
| S | Privacy & Data Compliance | Legal |
| T | Security Deposit Management | Finance |
| U | ADA / WCAG 2.1 Accessibility | Legal |
| V | Early Lease Termination | Operations |
| W | Platform Dispute Resolution | Legal |
| X | Platform Administration & DevOps | Infrastructure |
| Y | Promo Codes, Discounts & Gift Cards | Monetization |
| Z | Property Comparison Tool | Discovery |
| AA | Saved Search & Listing Alerts | Conversion |
| AB | Hunter Education & Certification Tracking | Compliance |
| AC | Platform Health, SLA & DR | Infrastructure |
| AD | Conservation & Environmental Overlays | Data |
| AE | Offline-First PWA Architecture | Mobile |
| AF | Partnership & Integration Ecosystem | Growth |
| AG | Harvest Data Monetization | Revenue |
| AH | Smart Lock & IoT Integration | Operations |
| AI | Bundled Insurance Products | Revenue |
| AJ | Landowner Succession & Estate Planning | Legal |
| AK | Agricultural & Land Use Conflict Management | Operations |
| AL | Sponsorship & Advertising Platform | Revenue |
| AM | Fishing Rights Management | Vertical |
| AN | Trust, Safety & Content Moderation | Safety |
| AO | NOAA Emergency Weather Alerts | Safety |
| AP | File Security — Virus & Malware Scanning | Security |
| AQ | ACH & Alternative Payment Methods | Finance |
| AR | AML / OFAC Compliance | Legal |
| AS | SAML / SSO for Enterprise | Enterprise |
| AT | Video Processing Pipeline | Infrastructure |
| AU | Platform Business Intelligence & Analytics | Intelligence |
| AV | Carbon Credits & Conservation Finance | Revenue |
| AW | Club Recruitment Board | Community |
| AX | Veteran & Military Programs | Community |
| AY | Youth & Junior Hunter Programs | Community |
| AZ | Exotic Game & High-Fence Operations | Vertical |
| BA | Extended Stay & Camp Management | Operations |
| BB | Bulk Import & Data Migration Tools | Onboarding |
| BC | Mineral & Timber Rights Tracking | Data |
| BD | Platform Automation & Workflow Engine | Operations |
| BE | Public API Developer Platform | Scale |
| BF | Wildlife Photography & Observation Tourism | Vertical |
| BG | QR Code Physical Integration | Operations |
| BH | Print & Offline Document Generation | Operations |
| BI | Cellular Dead Zone Mapping | Safety |
| BJ | Recreational Use Statute & Liability | Legal |
| BK | Multi-Species Lease Segmentation | Operations |
| BL | Lease Pause & Suspension Workflow | Operations |
| BM | Sublease & Co-Lessee Models | Legal |
| BN | Chargeback & Payment Dispute Management | Finance |
| BO | E-911 Address & What3Words Integration | Safety |
| BP | DMCA Compliance | Legal |
| BQ | Platform Insurance & Legal Structure | Legal |
| BR | Staging, UAT & Demo Environments | Infrastructure |
| BS | PgBouncer, Read Replicas & DB Monitoring | Infrastructure |
| BT | Regulatory Compliance Additions | Compliance |
| BU | Member Digital ID & Lease Pass | UX |
| BV | Lease Assignment & Transfer | Operations |
| BW | Landowner Response Metrics | Trust |
| BX | Two-Person Authorization | Security |
| BY | Infrastructure as Code & Containers | Infrastructure |
| BZ | Lease Wanted / Reverse Marketplace | Discovery |
| CA | Equipment Rental | Commerce |
| CB | Wildlife Population Modeling Tools | Intelligence |
| CC | Property Embed Widget | Growth |
| CD | Trophy Scoring Tools | Engagement |
| CE | Property Open House & Trial Hunt | Conversion |
| CF | Club Expense Sharing & Capital Improvement | Finance |
| CG | Synthetic Monitoring & Load Testing | Infrastructure |

**Total: 5 Core Portals + 93 Modules**

---

## Final Phase Plan

| Phase | Modules | Scope |
|---|---|---|
| **1** | Core, BR, BY | Admin backend, property CRUD, application flow, Stripe payments, document upload, onboarding, support ticketing, RBAC, Docker dev environment, IaC foundation |
| **2** | F, T, U, V, BJ | Legal compliance, state templates, RUS clauses, security deposits, ADA/WCAG, early termination |
| **3** | Member Portal, P, O, BL, BM | Lease lifecycle, e-signature, scheduling, renewal, negotiation, waitlist, lease pause, sublease/co-lessee |
| **4** | Q, Z, AA, BU, BW | Public frontend, virtual tours, comparison, saved search, digital ID card, landowner response metrics |
| **5** | SEO/Marketing | SEO architecture, state/county/species pages, GA4, Meta Pixel, GTM, social sharing |
| **6** | E, AW, CF | Hunting club/group leases, club recruitment board, club expense sharing |
| **7** | G, AB, AO, BO, BT | Incident and emergency, certifications, NOAA alerts, E-911/What3Words, regulatory additions |
| **8** | Wildlife Core, CB, CD | Harvest logging, quota, stand registry, CWD, season calendar, population modeling, trophy scoring |
| **9** | AM | Fishing rights management |
| **10** | AZ, BA, BK | Exotic game/high-fence, extended stay/camp, multi-species lease segmentation |
| **11** | A | Auction module — bidding engine, proxy bids, WebSocket real-time, winner flow |
| **12** | H, S, AR, BN | Financial/tax compliance, privacy compliance, AML/OFAC, chargeback management |
| **13** | W, AC | Dispute resolution, platform SLA and disaster recovery |
| **14** | X, BD, BX | Platform DevOps, automation/workflow engine, two-person authorization |
| **15** | AP, AT | File virus scanning, video processing pipeline |
| **16** | AQ, AS | ACH/alternative payments, SAML/SSO |
| **17** | BS | PgBouncer, read replicas, database monitoring |
| **18** | I, AF | Regulatory compliance, agency and organization partnerships |
| **19** | B, CE | Consulting marketplace, property open house/trial hunt |
| **20** | C | Outfitter and guide booking |
| **21** | D, CA | Equipment/services marketplace, equipment rental |
| **22** | AH | Smart lock and IoT integration |
| **23** | AI, BQ | Bundled insurance products, platform insurance structure |
| **24** | L, AU, BZ | Pricing intelligence, platform BI, lease wanted/reverse marketplace |
| **25** | J, Y | Marketing and growth tools, promo codes, gift cards |
| **26** | K | Customer support infrastructure |
| **27** | AD, AK | Conservation overlays, agricultural conflict management |
| **28** | AJ, BC | Landowner succession, mineral and timber rights |
| **29** | AN, BP | Trust, safety, content moderation, DMCA compliance |
| **30** | R | Gamification and community |
| **31** | AG | Harvest data monetization |
| **32** | AL | Sponsorship and advertising platform |
| **33** | AV | Carbon credits and conservation finance |
| **34** | AX, AY | Veteran/military programs, youth/junior programs |
| **35** | BF, CC | Wildlife photography/observation tourism, property embed widget |
| **36** | BG, BH | QR code physical integration, print/offline document generation |
| **37** | BI | Cellular dead zone mapping |
| **38** | BB | Bulk import and data migration tools |
| **39** | BV | Lease assignment and transfer |
| **40** | N | Multi-tenancy and white label |
| **41** | M | Platform integrations — OnX, trail cameras, calendar, weather, Zapier |
| **42** | AE | Offline-first PWA — full offline model, background sync, conflict resolution |
| **43** | BE | Public API developer platform — docs, sandbox, SDKs, partner program |
| **44** | CG | Synthetic monitoring, load testing, chaos engineering |
| **45** | PWA | Mobile PWA hardening — push, SOS, offline maps, QR check-in |
| **46** | Native App | React Native or Flutter native app evaluation and build |
| **47** | AI Layer | Trail cam recognition, smart lease matching, churn prediction, fraud detection, dynamic pricing, NLP lease review, support chatbot |

---

## What This Platform Is

This is not a hunting lease CMS. It is the **most comprehensively designed vertical SaaS platform in the outdoor recreation industry** — combining:

- A **two-sided marketplace** — landowners and hunters
- A **legal document platform** — leases, RUS compliance, signatures, state-specific law
- A **financial platform** — payments, escrow, payouts, tax compliance, AML, chargebacks
- A **wildlife management system** — harvest, quotas, herd modeling, fishing, exotic game
- A **safety platform** — incident management, SOS, E-911, IoT access, emergency weather
- A **community platform** — clubs, forums, gamification, recruitment, reverse marketplace
- A **commerce platform** — marketplace, auctions, outfitter bookings, equipment rental
- A **consulting platform** — habitat services, attorney review, conservation finance, population modeling
- A **data business** — harvest dataset, pricing intelligence, advertising audience, wildlife agency licensing
- An **enterprise platform** — white label, multi-tenancy, SOC 2, developer API, IaC

**14 purpose-built databases. 5 Valkey clusters. 47 delivery phases. 93 defined modules.**

**There is no platform like this anywhere in the world.**

---

*SCOPE FULLY AND FINALLY LOCKED.*
*Next step: Data modeling — table schemas per database, starting with Identity and Property.*
