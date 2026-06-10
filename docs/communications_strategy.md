# Communications Strategy

American Headhunter's communication stack is built in **two distinct layers** that serve different purposes and require different strategies:

- **Layer 1 — In-Platform Messaging** (build): Transactional chat tied to verified identity and real-world relationships — leases, clubs, hunts, outfitter bookings, support
- **Layer 2 — Community Hub** (integrate): General-interest hunting community for broader discussion, hosted on Discord with deep OAuth integration

This document covers both layers, the architecture, UX, safety integration, and admin controls.

---

## Why Two Layers?

A hunter on the platform isn't anonymous — they're a verified lessee of a specific property during a specific season, or a verified club member, or a paying outfitter client. That structured context makes some chat experiences fundamentally better when they're in-platform: the conversation is tied to the record, participants are proven to belong, and moderation happens against real identity.

But some conversations don't need that structure. "What's the best bourbon for a cold stand?" doesn't require verification — it's community. Building a general-purpose chat platform for that would be a 12–18 month engineering investment in a product category that isn't our business.

The split:

| Chat type | Layer | Reason |
|---|---|---|
| Landowner ↔ Lessee DMs | 1 | Lease-tied, auditable for disputes |
| Property lease rooms | 1 | Only verified lessees; scoped to the property |
| Club channels | 1 | Only verified members; club-specific |
| Hunt party chat | 1 | Auto-created per scheduled hunt, SOS-integrated |
| Outfitter ↔ Client threads | 1 | Booking-specific, service record |
| Consultant ↔ Client threads | 1 | Engagement-specific |
| Support chat | 1 | Staff-only access, ticket record |
| State hunters talking hunting | 2 | No verification needed; community enhances |
| Gear / bourbon / meme chat | 2 | Off-topic, doesn't need structure |
| Hunting news & weather talk | 2 | Broader than any one property |
| First buck photos, bragging | 2 | Community celebration |

---

# LAYER 1 — In-Platform Messaging

## Core Concept: Structured Chat, Not Free-Form Rooms

Unlike Discord where users create servers and channels, **every Layer 1 room is auto-created by the platform** based on real-world events:

- A lease gets signed → a lease room is created, lessees and landowner added
- A hunt is scheduled → a hunt party chat is created, participants added
- A club is formed → club channels are created, members added as they join
- An outfitter booking is confirmed → a client thread opens

Users never manually create rooms. This is a feature, not a limitation — it means every conversation is grounded in a real relationship, moderation is scoped to that relationship, and rooms auto-archive when the relationship ends.

---

## Room Types

### Direct Messages
One-to-one threads between any two users. Most common use cases:
- Prospective lessee → Landowner during application
- Active lessee → Landowner for property questions during the season
- Club officer → Member for administrative matters
- Outfitter → Client for pre-hunt coordination
- Consultant → Client for ongoing engagement

**Lifecycle:** Created when either user sends the first message. Never auto-archived — lives indefinitely. Either party can block the other (mutual block hides the thread on both sides).

### Property Lease Rooms
Group threads tied to a specific lease. Participants: the landowner + all lessees named on the lease.

**Auto-created** when `leases.status` transitions to `active`. A system message opens the room: *"This conversation is for the Brackettville Whitetail Ranch lease, Oct 5, 2026 – Jan 22, 2027. All 6 lessees and the landowner have access."*

**Content:**
- Property-specific announcements from landowner
- Trail cam photo sharing among the group
- Gate code changes, property updates, maintenance notices
- Harvest coordination (who's going when)
- Weather and condition discussion
- Group logistics

**Lifecycle:** Active during the lease period. Transitions to `read_only` status 90 days after lease expiration. Fully archived at 180 days but remains searchable in both participants' message history.

### Club Channels
Permanent multi-channel rooms created when a club is formed. Access-gated by `clubs.member_roster`.

**Channel structure (simpler than Discord):**

Basic Club (free tier):
- `#general` — all members
- `#hunts` — all members

Premium Club ($19/mo):
- `#announcements` — officers post, members read
- `#general` — all members
- `#hunts` — all members
- `#officers` — officers only (private)
- `#property-{name}` — auto-created per property the club leases
- Member-created channels: up to 3 additional (Premium feature)

**Lifecycle:** Persists as long as the club exists. When a member leaves the club, they lose access immediately. Historical messages they sent remain visible to current members.

### Hunt Party Chat
Ephemeral group threads created when a hunt is scheduled through Module N (Hunt Planning). Participants: all hunters on the specific hunt trip.

**Auto-created** when a hunt is scheduled with 2+ participants. System message: *"Hunt at Prairie Wind Buck Farm, Nov 15-17, 2026. 4 hunters confirmed."*

**Purpose:**
- Coordinate arrival times and meeting points
- Meal rotations and gear splitting
- Share real-time location updates during the hunt
- Report quick observations (rub lines, tracks, weather changes)
- **Safety check-ins** integrated with SOS system

**Lifecycle:** Active from creation through 72 hours after the scheduled end. Auto-archives at 7 days post-hunt but remains searchable.

### Outfitter / Consultant Client Threads
Scoped to a specific booking or engagement. Participants: the service provider + the client(s).

**Auto-created** when:
- An outfitter booking is confirmed (DB 6 `outfitter_bookings.status` → `confirmed`)
- A consulting engagement is signed (DB 6 `consultant_engagements.status` → `active`)

**Purpose:**
- Pre-service coordination
- Service-time communication
- Post-service wrap-up and reviews
- **Serves as an authoritative service record** if disputes arise

**Lifecycle:** Active during service period. Read-only 30 days after service completion. Archived at 180 days. Messages are preserved indefinitely for dispute resolution (governed by DB 9 audit retention policy).

### Support Tickets
Thread between a user and platform support staff. Tied to a `support_tickets` record in DB 7.

**Created:**
- When a user submits a support request
- When staff proactively opens a case (fraud alert, verification failure, etc.)

**Participants:** The requesting user + any staff assigned to the ticket. Multiple staff can be added as escalation happens.

**Lifecycle:** Active until ticket resolved. Read-only after resolution. Reopened automatically if user replies within 7 days of closure.

---

## Real-Time Architecture

### Backend Stack

| Component | Technology | Purpose |
|---|---|---|
| Message persistence | PostgreSQL (DB 7) | Source of truth; existing tables work fine |
| Real-time transport | **Laravel Reverb** (self-hosted) | WebSocket server for delivery |
| Presence & typing indicators | Valkey Cluster 2 (app cache) | Ephemeral state, no persistence needed |
| Push notifications (mobile) | Firebase Cloud Messaging | iOS + Android delivery |
| Push notifications (web) | Web Push API | Desktop browser delivery |
| SMS fallback | Twilio | For urgent messages when push fails |
| File uploads | Azure Blob (DB 11 metadata) | Images, PDFs, trail cam shots |
| Full-text search | PostgreSQL tsvector on `messages.content` | Adequate at platform scale |

### Laravel Reverb Configuration

Reverb is Laravel's first-party WebSocket server, included with Laravel 11 and deployable in the same Docker stack we already built. No external dependency on Pusher or Ably.

```php
// config/broadcasting.php
'default' => 'reverb',

'connections' => [
    'reverb' => [
        'driver' => 'reverb',
        'key' => env('REVERB_APP_KEY'),
        'secret' => env('REVERB_APP_SECRET'),
        'app_id' => env('REVERB_APP_ID'),
        'options' => [
            'host' => env('REVERB_HOST', '0.0.0.0'),
            'port' => env('REVERB_PORT', 8080),
            'scheme' => env('REVERB_SCHEME', 'http'),
        ],
    ],
],
```

Reverb runs as a separate container in the compose file:

```yaml
# addition to docker-compose.prod.yml
reverb:
  image: ahregistry.azurecr.io/american-headhunter/app:${APP_VERSION:-latest}
  container_name: ah_reverb
  restart: unless-stopped
  command: ["reverb"]
  env_file: .env.prod
  networks:
    - app_net
    - db_net
  ports:
    - "8080:8080"
  deploy:
    resources:
      limits:
        memory: 512M
```

Entrypoint handles the `reverb` role:

```bash
# docker/entrypoint.sh — add this case
reverb)
    echo "Starting Laravel Reverb WebSocket server..."
    exec php artisan reverb:start --host=0.0.0.0 --port=8080
    ;;
```

### Channel Authorization

Reverb channels are authorized via Laravel's existing auth system. Each channel checks that the user actually belongs:

```php
// routes/channels.php

// Direct message channel — only the two participants can subscribe
Broadcast::channel('dm.{threadId}', function (User $user, string $threadId) {
    $thread = MessageThread::on('communications')->find($threadId);
    return $thread && in_array($user->id, $thread->participant_ids);
});

// Lease room — only active lessees + landowner
Broadcast::channel('lease.{leaseId}', function (User $user, string $leaseId) {
    $lease = Lease::on('lease')->find($leaseId);
    if (!$lease) return false;

    $property = app(PropertyService::class)->find($lease->property_id);
    if ($property->landowner_user_id === $user->id) return true;

    return $lease->active_lessees()->where('user_id', $user->id)->exists();
});

// Club channel — only current members
Broadcast::channel('club.{clubId}.{channelKey}', function (User $user, string $clubId, string $channelKey) {
    $membership = ClubMember::on('lease')
        ->where('club_id', $clubId)
        ->where('user_id', $user->id)
        ->where('status', 'active')
        ->first();

    if (!$membership) return false;

    // Officers-only channel gating
    if ($channelKey === 'officers' && !$membership->is_officer) return false;

    return true;
});

// Hunt party — only hunters on the hunt
Broadcast::channel('hunt.{huntId}', function (User $user, string $huntId) {
    return app(HuntService::class)->userIsParticipant($user, $huntId);
});
```

### Presence Tracking

Valkey stores ephemeral presence state (who's online, who's typing) with short TTLs:

```php
// Presence key pattern
"presence:{userId}" => "online"          // TTL: 60 seconds
"typing:{threadId}:{userId}" => "1"      // TTL: 10 seconds
"last_read:{userId}:{threadId}" => ts    // No TTL, manual expiration
```

Frontend pings presence every 30 seconds. Typing state refreshes every 3 seconds while typing, expires 10 seconds after last activity.

---

## UX — Messaging in the Design System

The messaging UI uses the existing design system: Fraunces + Crimson Pro + JetBrains Mono, ink/parchment/blaze palette, sharp geometry. This isn't a separate "chat app" aesthetic — it's American Headhunter with a messaging surface.

### Thread List (left column, 320px)

```
┌───────────────────────────────────┐
│  MESSAGES                   [+]   │
│                                   │
│  ─── DIRECT ────────────────      │
│                                   │
│  ⬤  Robert W.                    │
│     Re: Stand 4 cleared...        │
│     2h · Kinney County, TX        │
│                                   │
│     James H.                      │
│     Club meeting Thursday...      │
│     yesterday                     │
│                                   │
│  ─── LEASE ROOMS ───────────       │
│                                   │
│  🌄 Brackettville Whitetail      │
│     6 lessees · Active            │
│     Marcus: "Weather looks..."    │
│     1h                            │
│                                   │
│  ─── CLUBS ─────────────────       │
│                                   │
│  🏛 River Bluff Hunting Club     │
│     #general · 14 members         │
│     New post from president       │
│     3h                            │
│                                   │
│  ─── HUNTS ─────────────────       │
│                                   │
│  🎯 Prairie Wind · Nov 15-17      │
│     4 hunters · 12 days away      │
│     Marcus: "I'm bringing..."     │
│     4h                            │
│                                   │
└───────────────────────────────────┘
```

Visual language:
- Monospace section headers (DIRECT / LEASE ROOMS / CLUBS / HUNTS) in blaze
- Active thread indicator: 8px blaze dot prefix
- Property/club/hunt context shown below sender name in small mono font
- Unread count as a small blaze pill
- Timestamps in mono, shortened (2h, yesterday, Mar 14)

### Thread View (main column)

```
┌─────────────────────────────────────────────────────────┐
│  ← Back        BRACKETTVILLE WHITETAIL LEASE        ⚙    │
│  6 lessees · Active · Oct 5 – Jan 22                    │
│  ───────────────────────────────────────                │
│                                                          │
│  Tuesday, Nov 12 ─────────────────                     │
│                                                          │
│  [Robert W. · Landowner]                                │
│  Just cleared Stand 4 after the wind last week.         │
│  All gates and roads passable.                          │
│  9:14 AM                                                 │
│                                                          │
│  [Marcus T.]                                             │
│  Thanks Robert. Planning to be out there Friday PM      │
│  through Sunday. Anyone else going to overlap?          │
│  10:22 AM                                                │
│                                                          │
│  [James H.]                                              │
│  I'm there Saturday morning. We can split up — I'll     │
│  take North Ridge, you can have South Bottoms?          │
│  10:35 AM                                                │
│                                                          │
│  ─── Robert W. shared trail cam ─────                   │
│  [ trail cam image: 8-pt buck, Nov 11 6:47 PM ]         │
│  Stand 4 camera. Regular visitor.                       │
│  11:12 AM                                                │
│                                                          │
│  Today ──────────────────────                            │
│                                                          │
│  [Marcus T.]                                             │
│  Weather looks promising. Low 28°F Sat morning.         │
│  2h ago                                                  │
│                                                          │
│  ───────────────────────────────────────                │
│  [ 📎 ]  Type a message...              [ Send → ]     │
└─────────────────────────────────────────────────────────┘
```

Key design choices:
- **Field Record header** at the top — matches the aesthetic from the website prototype. Shows context: who, when, what lease.
- **Date dividers** in monospace between days ("Tuesday, Nov 12")
- **Username prefix** in brackets with role badge (Landowner / Lessee / Officer) when relevant
- **Trail cam shares** as special bordered blocks, not inline images
- **Message composition** sits at bottom with a subtle parch-deep border
- **No rounded message bubbles** — just left-aligned text with small timestamps
- **System messages** in italic sage color, inset ("Robert W. shared trail cam")

### Notification Inbox

A unified inbox combining message notifications with platform notifications (application status, payment reminders, trail cam alerts, harvest reminders). See Notification Events section below for full event catalog.

---

## Safety Integration — SOS

Hunt party chats integrate with Module F (SOS Safety) in ways general-purpose chat can't:

### Check-In Flow

When a hunt is active, the hunt party chat shows a check-in widget:

```
┌────────────────────────────────────────────────┐
│  SAFETY CHECK-IN                               │
│  Scheduled check-in at 6:00 PM · 32 min away   │
│                                                │
│  [ I'm Safe ]  [ Extend by 30min ]  [ SOS ]   │
└────────────────────────────────────────────────┘
```

If a hunter doesn't check in:
- 6:00 PM: soft notification to hunter's device
- 6:05 PM: notification to other hunt party participants
- 6:15 PM: SMS to emergency contact
- 6:30 PM: automated SOS event created in DB 7 `sos_event_log` and DB 10 `sos_incident_records`

The hunt party chat becomes the first coordination space during an SOS event — other participants can confirm the hunter's last known location, report weather conditions, or note if the hunter said anything about a late return.

### Location Sharing

Optional per-hunt. When enabled, participants' locations update in the chat's sidebar map (using existing Mapbox integration). Stored transiently in Valkey (Cluster 4 — auction cluster, renamed conceptually but uses the same infrastructure) with 30-minute TTL. Never persisted to PostgreSQL unless an SOS event triggers permanent logging.

### Emergency Broadcast

Landowners and club officers can trigger emergency broadcasts that go to everyone currently on their property via SMS + push (e.g., "Wildfire spotted north of the property, evacuate immediately"). Logged in DB 7 `broadcast_messages` with `emergency_flag = true`.

---

## Moderation & Admin Controls

### Automated Moderation

Inbound messages run through a lightweight moderation pipeline:

1. **Rate limiting** — Valkey Cluster 5 limits messages per user per minute (configurable, default 20/min)
2. **Content filtering** — Pattern matching for prohibited content (phone numbers in DMs that could indicate off-platform deals, spam patterns, clear harassment)
3. **Attachment scanning** — Virus scan on all uploaded files before making them available to other users
4. **Link extraction** — Detected URLs get sandboxed unfurling and warnings for suspicious domains

Flagged messages aren't deleted automatically — they're held in a `pending_moderation` status and reviewed by staff.

### Manual Moderation

Admin backend (Filament) has a Moderation panel:

```
Admin → Communications → Moderation Queue

    Pending (12)   |   Flagged (3)   |   Historical

    ┌──────────────────────────────────────────────┐
    │ From: Marcus T. (hunter)                     │
    │ To:   Robert W. (landowner) via DM           │
    │ Sent: 2 minutes ago                          │
    │ Flag: Phone number pattern detected          │
    │                                              │
    │ "Sure, call me at 555-123-4567 and we can   │
    │  work out a private deal..."                 │
    │                                              │
    │  [ Approve ]  [ Hide ]  [ Delete ]           │
    │  [ Warn Sender ]  [ Suspend Sender ]          │
    └──────────────────────────────────────────────┘
```

Actions logged to DB 9 audit log with staff member, timestamp, message content, and action taken.

### User Controls

Users can:
- **Block** another user (bidirectional — both stop seeing each other's messages and profile)
- **Mute** a thread (stops notifications but thread still visible)
- **Report** a message (opens a support ticket with the message pre-attached)
- **Leave** a group (for clubs — handled through club membership; for hunt parties — cancels hunt participation)

### Immutable Log

Every message is retained in `messages` table regardless of user "deletion." Users can `deleted_at`-mark their own messages (soft-delete removes from UI), but the record remains for moderation, dispute resolution, and legal compliance. Only the admin "Delete" action hard-removes.

SOS-related messages in hunt party chats are **never** hard-deleted, consistent with the broader SOS-permanence rule in CLAUDE.md.

---

## Notification Events

Every meaningful event in the platform can generate notifications. Users control delivery channels (push / email / SMS) per event type in account settings.

### Priority tiers

| Priority | Delivery | Examples |
|---|---|---|
| **Critical** | All channels (push + SMS + email) regardless of settings | SOS trigger, emergency broadcast, gate code change |
| **High** | Push + email (SMS optional) | New lease application, signature request, payment failure |
| **Standard** | Push + email | New message, application status change, trail cam photo |
| **Low** | Email digest only | Weekly summary, newsletter, non-urgent platform news |

### Full event catalog

**Messaging events:**
- New direct message received
- New message in lease room (digest if multiple within 5 min)
- New message in club channel (digest if multiple within 5 min)
- New message in hunt party chat
- @mention in any thread
- Message flagged for moderation (sender notified)

**Lease lifecycle:**
- Application received (landowner)
- Application status changed (applicant)
- Lease ready for signature
- Lease signed — both parties
- Lease activation
- Lease renewal available (30 days before expiration)
- Payment due (configurable days before)
- Payment received
- Payment failed

**Property activity:**
- New trail cam photo (subscription-gated — Sportsman+)
- Gate code change (all active lessees)
- Property update from landowner
- Weather alert on property (severe only)

**Hunt planning:**
- Hunt scheduled (all participants)
- Check-in reminder (30 min before)
- Check-in missed (self + others)
- Hunt starting soon (day-of)

**Club activity:**
- Added to a club
- Club announcement posted
- Dues reminder
- Meeting scheduled

**Commercial:**
- Outfitter booking confirmed
- Consultant engagement started
- Marketplace sale completed
- Auction outbid (configurable frequency)
- Auction ending soon (1 hour, 10 min)
- Auction won

**Account:**
- Login from new device
- Password changed
- MFA enabled/disabled
- Subscription upgraded/downgraded
- Subscription expiration approaching
- Veteran verification complete
- Background check complete

Each event type has an admin-configurable template (email subject, email body, push title, push body, SMS body) stored in DB 12.

---

## Database — What Needs to Change in DB 7

The existing DB 7 schema from `db07_communications.md` covers most of this. Three additions:

### 1. Extend `message_threads.thread_type` enum

```sql
-- Current values in schema:
-- 'application', 'lease', 'support', 'outfitter', 'consulting', 'club'

-- Add new values:
ALTER TYPE thread_type_enum ADD VALUE 'direct';       -- DM between any two users
ALTER TYPE thread_type_enum ADD VALUE 'lease_room';   -- Group room for active lease
ALTER TYPE thread_type_enum ADD VALUE 'hunt_party';   -- Scheduled hunt group
ALTER TYPE thread_type_enum ADD VALUE 'club_channel'; -- Per-channel in a club
```

### 2. New `thread_metadata` column

```sql
ALTER TABLE message_threads
    ADD COLUMN metadata JSONB NOT NULL DEFAULT '{}';

-- Examples of metadata contents:
-- Lease room: { "lease_id": "...", "property_id": "...", "season_start": "...", "season_end": "..." }
-- Hunt party: { "hunt_id": "...", "property_id": "...", "scheduled_start": "...", "participant_count": 4 }
-- Club channel: { "club_id": "...", "channel_key": "general", "is_officers_only": false }
```

### 3. New `thread_archived_at` column

```sql
ALTER TABLE message_threads
    ADD COLUMN archived_at TIMESTAMPTZ,
    ADD COLUMN read_only_at TIMESTAMPTZ;

-- read_only_at: thread visible but no new messages accepted
-- archived_at: thread hidden from default inbox view but accessible via search
```

### 4. Moderation state on messages

```sql
ALTER TABLE messages
    ADD COLUMN moderation_status TEXT NOT NULL DEFAULT 'approved',
    ADD COLUMN moderation_flagged_at TIMESTAMPTZ,
    ADD COLUMN moderation_reviewed_at TIMESTAMPTZ,
    ADD COLUMN moderation_reviewer_id UUID,
    ADD COLUMN moderation_flags JSONB DEFAULT '[]';

-- moderation_status values: 'approved', 'pending', 'flagged', 'hidden', 'deleted_by_admin'
```

### 5. Typing/presence (Valkey, not PostgreSQL)

Presence and typing indicators don't go in PostgreSQL — they're ephemeral and live in Valkey Cluster 2 with short TTLs. See the Valkey key patterns in the Real-Time Architecture section above.

---

# LAYER 2 — Community Hub (Discord)

## Why Discord, Why Not Build It

Hunters already use Discord. The largest hunting Discord servers have tens of thousands of members. Building a general-community chat platform from scratch would require 12+ months of engineering for a product that isn't strategic to AH — the chat experience isn't what makes AH valuable, the lease marketplace is.

**The smart play:** Host an official American Headhunter Discord server, make it extremely valuable via deep integration with the platform, and let Discord handle the infrastructure complexity.

## Server Setup

**Name:** American Headhunter (with ornate/rustic logo as server icon)
**Invite URL:** `discord.gg/americanheadhunter` (vanity URL — requires Level 3 boost or partnership)

### Channel Structure

```
──── WELCOME ────────────────────────
#rules
#announcements                        [posts from AH bot]
#introductions
#getting-started

──── HUNTING TALK ───────────────────
#general
#whitetail-talk
#turkey-talk
#waterfowl-talk
#upland-and-quail
#exotic-and-western
#gear-and-optics
#reloading-and-ballistics

──── BY STATE ────────────────────────  [auto-created from listings]
#texas-hunters
#kansas-hunters
#alabama-hunters
#missouri-hunters
#georgia-hunters
... (one per state with active listings)

──── COMMUNITY ───────────────────────
#first-bucks
#trophy-showcase
#trail-cam-finds
#cooking-game
#off-topic

──── LEASES & LAND ───────────────────
#looking-for-a-lease
#offering-a-lease                     [auto-posts from AH]
#lease-tips
#landowner-lounge                     [verified landowners only]

──── OUTFITTERS & GUIDES ─────────────
#outfitter-showcase                   [auto-posts from AH]
#find-a-guide
#outfitter-lounge                     [verified outfitters only]

──── SUPPORT ─────────────────────────
#ah-support                           [staff monitored]
#feedback-and-ideas
#bug-reports

──── VOICE ───────────────────────────
Campfire (general lounge)
Strategy Session
Pre-Hunt Prep
After-Hunt Hangout
Staff Office Hours
```

### Role Structure

Roles synced from American Headhunter via the AH Discord Bot:

| Role | How earned | Perks in Discord |
|---|---|---|
| **Verified Member** | Linked AH account | Access to all non-private channels |
| **Verified Landowner** | Landowner account on AH | Access to #landowner-lounge |
| **Verified Outfitter** | Outfitter account, license + insurance verified on AH | Access to #outfitter-lounge, showcase channel posting |
| **Verified Consultant** | Consultant account, verified on AH | Consultant badge |
| **Club Officer** | Club officer role on AH | Officer badge |
| **Founding Member** | First 500 AH signups | Permanent "Founding Member" badge |
| **Veteran** | Veteran verified on AH | "Veteran" badge |
| **Sportsman** | Paid hunter tier on AH | "Sportsman" badge, custom color |
| **Ranch/Estate** | Paid landowner tier on AH | Tier badge |
| **Staff** | AH employees | Full moderation permissions |
| **Community Mod** | Community volunteers | Moderation in specific channels |

Badges reinforce that being on AH has status in the community.

---

## AH Discord Bot

A custom bot (Python or Node.js, running as a small service — can be its own container in the compose file at ~$5/mo resource cost) that handles integration between American Headhunter and the Discord server.

### Bot responsibilities

**OAuth linking**
- Users can run `/link` in DM with the bot
- Bot sends a link to AH's OAuth endpoint
- User authorizes — Discord user ID gets linked to AH user ID
- Bot assigns appropriate roles based on AH account status

**Role syncing (every 15 min via cron)**
- Bot queries AH API for all linked users
- Updates Discord roles based on current AH status
- Removes roles for cancelled subscriptions, banned users, etc.
- Adds Veteran role when ID.me verification completes

**Auto-posting**
- New property listing published → bot posts to `#offering-a-lease` and the appropriate state channel
- Auction ending in 24 hours → bot posts to `#offering-a-lease`
- New outfitter package → bot posts to `#outfitter-showcase`
- Major platform announcement → bot posts to `#announcements`

**Moderation actions**
- If a user is banned on AH, bot automatically bans on Discord
- If a user's subscription becomes fraudulent, bot removes paid-tier roles
- Staff can use `/ah-lookup {discord_user}` to see their AH account info

**Community commands**
- `/find-lease state:Texas species:Whitetail` — returns 3-5 matching listings with deep links
- `/my-leases` — shows user's active leases
- `/my-hunts` — shows user's upcoming scheduled hunts
- `/weather {property_id}` — fetches weather forecast for a property
- `/help` — bot help menu

### Technical implementation

```
Bot stack:
- Python 3.12 + py-cord OR Node.js + discord.js
- Small container in docker-compose
- Reads from AH API via service token
- Posts to Discord API via bot token
- State cached in Valkey for performance

Bot container:
  image: ahregistry.azurecr.io/american-headhunter/discord-bot:latest
  container_name: ah_discord_bot
  restart: unless-stopped
  env_file: .env.prod
  networks:
    - app_net
  deploy:
    resources:
      limits:
        memory: 256M
```

New API endpoints on AH to support the bot:
- `GET /api/internal/users/{id}/roles` — returns role list for syncing
- `GET /api/internal/listings/recent` — for auto-posting
- `GET /api/internal/users/by-discord/{discord_id}` — reverse lookup for moderation

These endpoints are authenticated with a service token (DB 1 `api_clients` table with a dedicated client for the bot) and scope-limited.

---

## OAuth Integration Flow

```
User clicks "Link Discord" in AH account settings
  │
  ▼
AH generates OAuth state, redirects to Discord OAuth
  │
  ▼
User authorizes on Discord (or creates Discord account if needed)
  │
  ▼
Discord redirects back to AH with authorization code
  │
  ▼
AH exchanges code for Discord user info
  │
  ▼
AH stores discord_user_id in users.discord_user_id
  │
  ▼
AH makes API call to AH Discord Bot: "Link complete — assign roles to Discord user X"
  │
  ▼
Bot assigns Verified Member + tier-specific roles
  │
  ▼
User sees success message on AH, and a welcome DM on Discord from the bot
```

Reverse flow also supported: user joins the Discord server first, bot DMs them with a link to AH, clicking signs them up with Discord attribution.

### Database additions to DB 1

```sql
ALTER TABLE users
    ADD COLUMN discord_user_id VARCHAR(50),
    ADD COLUMN discord_linked_at TIMESTAMPTZ,
    ADD COLUMN discord_last_synced_at TIMESTAMPTZ;

CREATE UNIQUE INDEX idx_users_discord ON users(discord_user_id)
    WHERE discord_user_id IS NOT NULL;
```

---

## Cross-Platform Value Flow

The integration makes both AH and the Discord server more valuable:

**AH → Discord (what makes Discord better):**
- Real verification badges that Discord can't provide natively
- Deep links to actual lease listings
- Real-time lease availability alerts
- Cross-community hunt coordination (find someone with a lease near you)
- Status-tier visual identity

**Discord → AH (what makes AH better):**
- Lower CAC — community members convert to AH accounts
- Support load reduced — community answers common questions
- Content generation — user-generated discussion powers blog content
- Real-time user feedback — product team can see issues discussed immediately
- Retention — users stay engaged between hunting seasons
- Tribal knowledge — new users learn from experienced community

---

## Launching the Discord Server

**Phase 1 (Pre-launch)**
- Set up server structure, roles, channels
- Invite AH staff and early supporters (first 20-30 people)
- Bot deployed and tested in a staging server
- Moderation policies drafted

**Phase 2 (Soft launch — first 500 AH signups)**
- Discord invite sent to every new AH signup via welcome email
- Founding Member role auto-granted
- Staff monitor every channel daily
- First community events (weekly "Office Hours" voice sessions)

**Phase 3 (Public launch)**
- Vanity URL activated (requires partnership application to Discord)
- Cross-promotion on AH landing page ("Join 2,000+ hunters on our Discord")
- Hunting podcast partnerships promote the Discord
- Seasonal events (pre-season planning week, opening day watch parties)

**Phase 4 (Community-led moderation)**
- Identify 3-5 active community members for Community Mod role
- Establish moderation escalation process
- Weekly mod call with AH staff
- Community-led events (user-organized hunt swap meets, gear reviews)

---

## Moderation Philosophy

American Headhunter's Discord is a **community of practice**, not a generic social server. That means:

**Expected:**
- Talking about hunting, gear, strategy, stories
- Helping newer hunters learn
- Sharing successes and lessons from failures
- Respectful debate on gear, methods, ethics
- Cross-promoting personal content (within reason)

**Not tolerated:**
- Doxxing, harassment, personal attacks
- Off-platform lease deals that circumvent AH (removes revenue and the protections AH provides)
- Poaching advocacy, illegal methods
- Politics unrelated to hunting policy
- Gatekeeping new hunters

Moderation actions follow the standard progression: warn → mute → kick → ban, with AH account consequences for severe violations. Banning someone from Discord doesn't auto-ban them from AH, but severe AH violations (fraud, harassment) do auto-ban from Discord via the bot.

---

## Future Considerations

**Where Discord might not be enough eventually:**
- Long-form content (gear reviews, hunting reports) — consider a blog/forum hybrid
- Searchable knowledge base of past discussions — Discord search is weak
- Rich profile pages — Discord profiles are limited
- E-commerce integration — selling through a community needs more than Discord

**If the community grows past ~50,000 active users**, consider:
- Migrating to or complementing with a self-hosted forum (Discourse, Circle, or custom)
- Building a members-only content section on AH itself
- Hosting physical community events (regional meetups, annual AH gathering)

But these are 2-3 years away at earliest. Discord is the right call for the next 24 months.

---

## Summary

| Decision | Answer |
|---|---|
| Build a full chat platform? | **No** — 18 months of engineering for a non-core product |
| Just link to Discord? | **No** — loses transactional chat value |
| Build Layer 1 (in-platform messaging)? | **Yes** — leverages existing DB 7 schema, unique value from verified identity |
| Integrate with Discord for Layer 2? | **Yes** — hunters already use it, bot integration adds value both ways |
| Real-time tech for Layer 1? | **Laravel Reverb** (first-party, in the Docker stack) |
| Push notifications? | **Firebase Cloud Messaging** for mobile, **Web Push** for desktop |
| Moderation approach? | **Automated screening** + **staff review queue** + **community mods** |
| Safety integration? | **Hunt party chats integrate with SOS system** — unique value Discord can't provide |
| DB changes needed? | **Minimal** — 4 ALTER statements to DB 7 tables + 3 columns on DB 1 users |
