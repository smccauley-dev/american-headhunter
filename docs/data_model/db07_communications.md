# DB 7 — Communications & Notifications

**Connection:** `communications`
**Database:** `ah_communications`
**App User:** `ah_app`
**Server:** High-write, time-series optimized PostgreSQL — WAL-heavy config, append-biased workload
**Encryption Key:** Key G — rotated annually via Azure Key Vault
**Extensions:** `pgcrypto`, `uuid-ossp`
**RLS Enabled:** Yes — on `message_threads`, `messages`, `support_tickets`
**Real-time:** Laravel Reverb (WebSocket) broadcasts events from this database

This database manages all platform communication: structured message threads between parties (lease/application/support/club/direct/SOS contexts), notification delivery tracking, push subscription management, support ticket workflows, SOS life-safety events, and Discord community webhook configuration.

**`sos_event_log` rows are NEVER deleted.** They are permanent life-safety records. The model does not extend `ImmutableModel` (that pattern is for DB 9 only), but a PostgreSQL RULE blocks `DELETE` on the table at the engine level and the model overrides `delete()` to throw.

Real-time message delivery is handled by Laravel Reverb WebSocket server. When a message is inserted, `MessageBroadcastJob` publishes to the Reverb channel `thread.{thread_id}`. Clients subscribe on page load via Echo.

---

## Tables

### `message_threads`

Container for a conversation. Each thread has a `thread_type` that determines who can participate and what UI is shown.

```sql
CREATE TABLE message_threads (
    id                       UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    thread_type              VARCHAR(15) NOT NULL
                                 CHECK (thread_type IN ('lease', 'application', 'support', 'direct', 'club', 'sos')),
    subject                  VARCHAR(255) NULL,
    status                   VARCHAR(10) NOT NULL DEFAULT 'active'
                                 CHECK (status IN ('active', 'archived', 'closed')),
    related_lease_id         UUID        NULL,  -- References DB 3 (Lease) leases.id
    related_application_id   UUID        NULL,  -- References DB 3 (Lease) lease_applications.id
    created_by_user_id       UUID        NOT NULL,  -- References DB 1 (Identity) users.id
    created_at               TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at               TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    deleted_at               TIMESTAMPTZ NULL
);

CREATE INDEX idx_message_threads_type            ON message_threads (thread_type);
CREATE INDEX idx_message_threads_status          ON message_threads (status);
CREATE INDEX idx_message_threads_lease_id        ON message_threads (related_lease_id)       WHERE related_lease_id IS NOT NULL;
CREATE INDEX idx_message_threads_application_id  ON message_threads (related_application_id) WHERE related_application_id IS NOT NULL;
CREATE INDEX idx_message_threads_created_by      ON message_threads (created_by_user_id);
CREATE INDEX idx_message_threads_deleted_at      ON message_threads (deleted_at) WHERE deleted_at IS NOT NULL;

CREATE TRIGGER trg_message_threads_updated_at
    BEFORE UPDATE ON message_threads
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
```

**RLS Policy:**
```sql
ALTER TABLE message_threads ENABLE ROW LEVEL SECURITY;

CREATE POLICY message_threads_participants ON message_threads
    FOR ALL TO ah_app
    USING (
        id IN (
            SELECT thread_id FROM thread_participants
            WHERE user_id = current_setting('app.current_user_id')::UUID
              AND left_at IS NULL
        )
        OR current_setting('app.current_role') IN ('staff', 'super_admin')
    );
```

**Thread type behaviors:**

| thread_type | Created by | Participants | Notes |
|---|---|---|---|
| `lease` | System | Lessee + Lessor | Created at lease activation |
| `application` | System | Applicant + Landowner | Created at application submission |
| `support` | User or staff | User + Assigned staff | Linked to `support_tickets` |
| `direct` | Either party | 2 users | Direct messages between any users |
| `club` | Club admin | All club members | One thread per club |
| `sos` | System (SOS trigger) | Hunter + Emergency contacts + Staff | Created when SOS is triggered |

---

### `thread_participants`

Membership roster for each thread. Tracks read position per participant.

```sql
CREATE TABLE thread_participants (
    id           UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    thread_id    UUID        NOT NULL REFERENCES message_threads (id) ON DELETE CASCADE,
    user_id      UUID        NOT NULL,  -- References DB 1 (Identity) users.id
    role         VARCHAR(10) NOT NULL DEFAULT 'member'
                     CHECK (role IN ('owner', 'member')),
    last_read_at TIMESTAMPTZ NULL,
    joined_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    left_at      TIMESTAMPTZ NULL,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX uq_thread_participants_thread_user ON thread_participants (thread_id, user_id);
CREATE        INDEX idx_thread_participants_user_id    ON thread_participants (user_id);
CREATE        INDEX idx_thread_participants_thread_id  ON thread_participants (thread_id);
```

**Notes:**
- `last_read_at` is updated when the user opens the thread. Unread count = messages after `last_read_at`.
- `left_at IS NOT NULL` means the user has left the thread (for direct/club threads). They are not removed from the participant list for audit purposes.
- `NotificationService` uses this table to determine which participants to notify on a new message.

---

### `messages`

Individual messages within a thread. Supports plain text and markdown. Attachment document IDs are cross-DB references to DB 11.

```sql
CREATE TABLE messages (
    id                    UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    thread_id             UUID        NOT NULL REFERENCES message_threads (id) ON DELETE CASCADE,
    sender_user_id        UUID        NOT NULL,  -- References DB 1 (Identity) users.id
    body                  TEXT        NOT NULL,
    body_type             VARCHAR(10) NOT NULL DEFAULT 'text'
                              CHECK (body_type IN ('text', 'markdown')),
    attachment_document_ids JSONB     NOT NULL DEFAULT '[]',  -- array of document_ids from DB 11
    is_system_message     BOOLEAN     NOT NULL DEFAULT false,
    edited_at             TIMESTAMPTZ NULL,
    created_at            TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    deleted_at            TIMESTAMPTZ NULL
);

CREATE        INDEX idx_messages_thread_id        ON messages (thread_id);
CREATE        INDEX idx_messages_sender_user_id   ON messages (sender_user_id);
CREATE        INDEX idx_messages_created_at       ON messages (thread_id, created_at DESC);
CREATE        INDEX idx_messages_deleted_at       ON messages (deleted_at) WHERE deleted_at IS NOT NULL;
CREATE        INDEX idx_messages_attachments_gin  ON messages USING GIN (attachment_document_ids)
    WHERE jsonb_array_length(attachment_document_ids) > 0;
```

**RLS Policy:**
```sql
ALTER TABLE messages ENABLE ROW LEVEL SECURITY;

CREATE POLICY messages_participants_only ON messages
    FOR ALL TO ah_app
    USING (
        thread_id IN (
            SELECT thread_id FROM thread_participants
            WHERE user_id = current_setting('app.current_user_id')::UUID
              AND left_at IS NULL
        )
        OR current_setting('app.current_role') IN ('staff', 'super_admin')
    );
```

**Notes:**
- `is_system_message = true` for automated messages (e.g., "Lease has been activated", "Payment received"). These are generated by service classes, not users.
- `edited_at` is set when a user edits a message within the allowed edit window (configurable in DB 12 platform settings). Edit history is not stored.
- Deleted messages (`deleted_at IS NOT NULL`) display as "[Message deleted]" in the UI. The body is retained for moderation purposes.
- After insert, `MessageBroadcastJob` publishes to Reverb channel `private-thread.{thread_id}`.

---

### `notifications`

Delivery tracking for all notifications across all channels (in-app, email, SMS, push). Each notification delivery attempt creates one row.

```sql
CREATE TABLE notifications (
    id              UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    user_id         UUID        NOT NULL,  -- References DB 1 (Identity) users.id
    type            VARCHAR(100) NOT NULL,
        -- e.g., 'lease.activated', 'bid.outbid', 'message.received', 'sos.triggered'
    channel         VARCHAR(10) NOT NULL
                        CHECK (channel IN ('in_app', 'email', 'sms', 'push')),
    title           VARCHAR(255) NOT NULL,
    body            TEXT        NOT NULL,
    data            JSONB       NOT NULL DEFAULT '{}',
    read_at         TIMESTAMPTZ NULL,  -- in_app only
    sent_at         TIMESTAMPTZ NULL,
    failed_at       TIMESTAMPTZ NULL,
    failure_reason  VARCHAR(255) NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Append-only pattern — no updated_at, no deleted_at
CREATE INDEX idx_notifications_user_id    ON notifications (user_id);
CREATE INDEX idx_notifications_channel    ON notifications (channel);
CREATE INDEX idx_notifications_type       ON notifications (type);
CREATE INDEX idx_notifications_created_at ON notifications (user_id, created_at DESC);
CREATE INDEX idx_notifications_read_at    ON notifications (user_id, read_at) WHERE read_at IS NULL;
CREATE INDEX idx_notifications_data_gin   ON notifications USING GIN (data);
```

**Notes:**
- Unread in-app notifications: `WHERE user_id = ? AND channel = 'in_app' AND read_at IS NULL`.
- `data` contains contextual payload for the notification type: `{"lease_id": "...", "property_title": "..."}`. Never include PII or payment details in `data`.
- `NotificationService` creates rows here after dispatching to the appropriate delivery channel (Laravel Mail, Twilio SMS, APNS/FCM push).
- Old notifications are pruned after 90 days by `PurgeOldNotificationsJob` (in_app and email only; sms and push records are kept for 365 days per compliance).

---

### `push_subscriptions`

Web push (VAPID) and mobile push notification credentials. Auth keys are encrypted at rest.

```sql
CREATE TABLE push_subscriptions (
    id                  UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    user_id             UUID        NOT NULL,  -- References DB 1 (Identity) users.id
    platform            VARCHAR(10) NOT NULL
                            CHECK (platform IN ('web', 'ios', 'android')),
    endpoint            TEXT        NOT NULL,  -- push service URL (web) or device token (mobile)
    auth_key_encrypted  TEXT        NULL,  -- encrypted (pgp_sym_encrypt) — VAPID auth key (web only)
    p256dh_key_encrypted TEXT       NULL,  -- encrypted (pgp_sym_encrypt) — VAPID p256dh key (web only)
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    deleted_at          TIMESTAMPTZ NULL
);

CREATE INDEX idx_push_subscriptions_user_id  ON push_subscriptions (user_id);
CREATE INDEX idx_push_subscriptions_platform ON push_subscriptions (platform);
CREATE INDEX idx_push_subscriptions_deleted  ON push_subscriptions (deleted_at) WHERE deleted_at IS NOT NULL;

CREATE TRIGGER trg_push_subscriptions_updated_at
    BEFORE UPDATE ON push_subscriptions
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
```

**Notes:**
- `auth_key_encrypted` and `p256dh_key_encrypted` are web push VAPID keys — only present for `platform = 'web'`.
- For `ios` and `android`, `endpoint` is the device push token (APNs token for iOS, FCM registration ID for Android). `auth_key_encrypted` and `p256dh_key_encrypted` are null.
- Expired or invalid subscriptions (indicated by push delivery failure) are soft-deleted by `PurgeInvalidPushSubscriptionsJob`.
- Never log the decrypted auth keys or p256dh keys.

---

### `support_tickets`

Customer support tickets submitted by users or created by staff on their behalf.

```sql
CREATE TABLE support_tickets (
    id                  UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    user_id             UUID        NOT NULL,  -- References DB 1 (Identity) users.id
    subject             VARCHAR(255) NOT NULL,
    status              VARCHAR(15) NOT NULL DEFAULT 'open'
                            CHECK (status IN ('open', 'in_progress', 'waiting', 'resolved', 'closed')),
    priority            VARCHAR(10) NOT NULL DEFAULT 'normal'
                            CHECK (priority IN ('low', 'normal', 'high', 'urgent')),
    category            VARCHAR(50) NOT NULL,
        -- 'billing', 'lease_dispute', 'account', 'property', 'safety', 'technical', 'other'
    assigned_to_user_id UUID        NULL,  -- References DB 1 (Identity) users.id (staff member)
    resolved_at         TIMESTAMPTZ NULL,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    deleted_at          TIMESTAMPTZ NULL
);

CREATE INDEX idx_support_tickets_user_id      ON support_tickets (user_id);
CREATE INDEX idx_support_tickets_status       ON support_tickets (status);
CREATE INDEX idx_support_tickets_priority     ON support_tickets (priority);
CREATE INDEX idx_support_tickets_assigned_to  ON support_tickets (assigned_to_user_id) WHERE assigned_to_user_id IS NOT NULL;
CREATE INDEX idx_support_tickets_deleted_at   ON support_tickets (deleted_at) WHERE deleted_at IS NOT NULL;

CREATE TRIGGER trg_support_tickets_updated_at
    BEFORE UPDATE ON support_tickets
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
```

**RLS Policy:**
```sql
ALTER TABLE support_tickets ENABLE ROW LEVEL SECURITY;

CREATE POLICY support_tickets_owner_or_staff ON support_tickets
    FOR ALL TO ah_app
    USING (
        user_id = current_setting('app.current_user_id')::UUID
        OR assigned_to_user_id = current_setting('app.current_user_id')::UUID
        OR current_setting('app.current_role') IN ('staff', 'super_admin')
    );
```

---

### `support_messages`

Messages within a support ticket. Internal messages (from staff to staff) are hidden from the end user.

```sql
CREATE TABLE support_messages (
    id               UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    ticket_id        UUID        NOT NULL REFERENCES support_tickets (id) ON DELETE CASCADE,
    sender_user_id   UUID        NOT NULL,  -- References DB 1 (Identity) users.id
    body             TEXT        NOT NULL,
    is_internal      BOOLEAN     NOT NULL DEFAULT false,
    created_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    deleted_at       TIMESTAMPTZ NULL
);

CREATE INDEX idx_support_messages_ticket_id ON support_messages (ticket_id);
CREATE INDEX idx_support_messages_created   ON support_messages (ticket_id, created_at);
```

---

### `sos_event_log`

Life-safety SOS (emergency distress) events. Triggered by the hunter pressing the SOS button in the app. Records are **permanent** — never soft-deleted or hard-deleted. A PostgreSQL RULE blocks any `DELETE` at the engine level.

```sql
CREATE TABLE sos_event_log (
    id                              UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    user_id                         UUID         NOT NULL,  -- References DB 1 (Identity) users.id
    lease_id                        UUID         NULL,  -- References DB 3 (Lease) leases.id
    triggered_at                    TIMESTAMPTZ  NOT NULL,
    location_lat                    NUMERIC(10,8) NULL,
    location_lng                    NUMERIC(11,8) NULL,
    location_accuracy_meters        SMALLINT     NULL,
    status                          VARCHAR(15)  NOT NULL DEFAULT 'active'
                                        CHECK (status IN ('active', 'responding', 'resolved')),
    resolved_at                     TIMESTAMPTZ  NULL,
    resolution_notes                TEXT         NULL,
    emergency_contacts_notified     JSONB        NOT NULL DEFAULT '[]',
        -- array of: {"contact_name": "...", "phone": "...", "notified_at": "..."}
    created_at                      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
    -- NO deleted_at column — this record is LEGALLY PERMANENT
    -- NO updated_at trigger — status updates are permitted but append-semantics preferred
);

-- Block DELETE at the PostgreSQL engine level (defense-in-depth)
CREATE RULE no_delete_sos_event_log AS
    ON DELETE TO sos_event_log DO INSTEAD NOTHING;

CREATE INDEX idx_sos_event_log_user_id      ON sos_event_log (user_id);
CREATE INDEX idx_sos_event_log_lease_id     ON sos_event_log (lease_id) WHERE lease_id IS NOT NULL;
CREATE INDEX idx_sos_event_log_triggered_at ON sos_event_log (triggered_at DESC);
CREATE INDEX idx_sos_event_log_status       ON sos_event_log (status);
```

**Notes:**
- **This table uses a different permanent-record pattern than DB 9 (Audit).** DB 9 uses `ImmutableModel` + PostgreSQL RULE. `sos_event_log` uses a PostgreSQL RULE only — the model does not extend `ImmutableModel`, because `status` and `resolved_at` are legitimately updated as the incident is managed. What is blocked is `DELETE`.
- `SosService::trigger()` creates the event log row, creates an SOS thread (`thread_type = 'sos'`), notifies emergency contacts, and dispatches `DispatchSosAlertJob` (priority queue).
- `location_lat` / `location_lng` are the GPS coordinates at the time of the SOS trigger. If GPS was unavailable, both are null.
- `emergency_contacts_notified` is populated as contacts are actually reached. It is an immutable-ish record; append new entries, never remove.
- The corresponding incident record lives in DB 10 (`sos_incident_records`), created by `SosService` after the event log row.

---

### `discord_webhooks`

Configuration for Discord community integration. Each webhook can be scoped to specific event types. Webhook URLs are encrypted at rest.

```sql
CREATE TABLE discord_webhooks (
    id                  UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    guild_id            VARCHAR(100) NOT NULL,
    channel_id          VARCHAR(100) NOT NULL,
    webhook_url_encrypted TEXT       NOT NULL,  -- encrypted (pgp_sym_encrypt)
    event_types         JSONB       NOT NULL DEFAULT '[]',
        -- array of event type strings: ["harvest.logged", "auction.started", "lease.activated"]
    is_active           BOOLEAN     NOT NULL DEFAULT true,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE        INDEX idx_discord_webhooks_guild_id    ON discord_webhooks (guild_id);
CREATE        INDEX idx_discord_webhooks_is_active   ON discord_webhooks (is_active);
CREATE        INDEX idx_discord_webhooks_event_types ON discord_webhooks USING GIN (event_types);

CREATE TRIGGER trg_discord_webhooks_updated_at
    BEFORE UPDATE ON discord_webhooks
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
```

**Notes:**
- Webhook URLs are encrypted — never log or return the decrypted URL in API responses.
- `SendDiscordWebhookJob` looks up all active webhooks matching the event type and delivers the payload asynchronously.
- Multiple guilds may be configured (e.g., a state-specific hunting Discord server subscribes to harvests from properties in their state).

---

## Eloquent Models

### `App\Models\Communications\MessageThread`

```php
<?php

namespace App\Models\Communications;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MessageThread extends Model
{
    use SoftDeletes;

    protected $connection = 'communications';
    protected $table      = 'message_threads';

    public $timestamps   = false;
    public $incrementing = false;
    protected $keyType   = 'string';

    protected $fillable = [
        'thread_type',
        'subject',
        'status',
        'related_lease_id',
        'related_application_id',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function participants(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ThreadParticipant::class, 'thread_id');
    }

    public function messages(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Message::class, 'thread_id')
                    ->whereNull('deleted_at')
                    ->orderByDesc('created_at');
    }
}
```

### `App\Models\Communications\Message`

```php
protected $connection = 'communications';
protected $table      = 'messages';
public $timestamps    = false;
public $incrementing  = false;
protected $keyType    = 'string';

protected function casts(): array
{
    return [
        'attachment_document_ids' => 'array',
        'is_system_message'       => 'boolean',
        'edited_at'               => 'datetime',
        'created_at'              => 'datetime',
        'deleted_at'              => 'datetime',
    ];
}
```

### `App\Models\Communications\Notification`

```php
protected $connection = 'communications';
protected $table      = 'notifications';
public $timestamps    = false;
public $incrementing  = false;
protected $keyType    = 'string';

protected function casts(): array
{
    return [
        'data'       => 'array',
        'read_at'    => 'datetime',
        'sent_at'    => 'datetime',
        'failed_at'  => 'datetime',
        'created_at' => 'datetime',
    ];
}
```

### `App\Models\Communications\SosEventLog`

```php
<?php

namespace App\Models\Communications;

use Illuminate\Database\Eloquent\Model;

class SosEventLog extends Model
{
    protected $connection = 'communications';
    protected $table      = 'sos_event_log';

    public $timestamps   = false;
    public $incrementing = false;
    protected $keyType   = 'string';

    // Status updates (active → responding → resolved) are permitted
    protected $fillable = [
        'status',
        'resolved_at',
        'resolution_notes',
        'emergency_contacts_notified',
    ];

    protected function casts(): array
    {
        return [
            'triggered_at'                => 'datetime',
            'resolved_at'                 => 'datetime',
            'emergency_contacts_notified' => 'array',
            'created_at'                  => 'datetime',
        ];
    }

    // SOS records are permanent life-safety documents — deletion is categorically prohibited
    public function delete(): bool
    {
        throw new \LogicException('SOS event log records are permanent life-safety records and cannot be deleted.');
    }

    public function forceDelete(): bool
    {
        throw new \LogicException('SOS event log records are permanent life-safety records and cannot be deleted.');
    }
}
```

### `App\Models\Communications\SupportTicket`

```php
protected $connection = 'communications';
protected $table      = 'support_tickets';
public $timestamps    = false;
public $incrementing  = false;
protected $keyType    = 'string';

protected function casts(): array
{
    return [
        'resolved_at' => 'datetime',
        'created_at'  => 'datetime',
        'updated_at'  => 'datetime',
        'deleted_at'  => 'datetime',
    ];
}
```

---

## Service Notes

- **`NotificationService`** — orchestrates all notification delivery across channels. Reads `thread_participants` to determine recipients. At `App\Services\Communications\NotificationService`.
- **`SosService`** — SOS trigger, event log creation, emergency contact notification, staff alert, incident record creation in DB 10. At `App\Services\Communications\SosService`. SOS alert dispatch runs on the `priority` queue.
- **`MessageService`** — thread creation, message send, participant management, read-state tracking, Reverb broadcast dispatch. At `App\Services\Communications\MessageService`.
- **`DiscordService`** — looks up matching webhooks and delivers event payloads to Discord. At `App\Services\Communications\DiscordService`.
- **Queue jobs:**
  - Priority queue: `DispatchSosAlertJob`
  - Default queue: `SendEmailNotificationJob`, `SendSmsNotificationJob`, `SendPushNotificationJob`, `MessageBroadcastJob`, `SendDiscordWebhookJob`, `PurgeOldNotificationsJob`, `PurgeInvalidPushSubscriptionsJob`
- **Laravel Reverb channels:** `private-thread.{thread_id}` (authenticated — participant only), `private-notifications.{user_id}` (authenticated — user only)
- SOS threads (`thread_type = 'sos'`) are automatically created by `SosService` — never created manually. They are linked to both the `sos_event_log` row (via `related_lease_id` as a proxy) and the `sos_incident_records` row in DB 10.
