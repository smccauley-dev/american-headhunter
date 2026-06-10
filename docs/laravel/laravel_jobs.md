# Laravel Queue Jobs — Architecture & Conventions

Queue jobs run on Valkey Cluster 3 (`queue` connection). This document covers the queue architecture, job class conventions, the full job inventory by domain, and failure handling.

---

## Queue Architecture

### Connection

The `valkey` queue connection is defined in `config/queue.php`:

```php
'valkey' => [
    'driver'      => 'redis',
    'connection'  => 'queue',          // Valkey Cluster 3
    'queue'       => 'default',        // string only — array form causes "Array to string conversion"
    'retry_after' => 90,
    'block_for'   => null,
    'after_commit' => false,
],
```

### Two Named Queues

| Queue | `retry_after` | Domain | Purpose |
|---|---|---|---|
| `priority` | 30 seconds | Safety, payments, signatures, lease activation | Time-critical operations where delay causes harm |
| `default` | 90 seconds | Notifications, media, ETL, generation | Everything else |

Both queues share the same Valkey Cluster 3 container. There is no second queue connection — just two named queues on the same connection.

### Worker Configuration

Defined in `docker/supervisor/supervisord.conf`:

```ini
[program:queue-worker]
command=php /var/www/html/artisan queue:work valkey --queue=priority,default --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
numprocs=2
process_name=%(program_name)s_%(process_num)02d
```

Two worker processes start automatically via Supervisor. Both process `priority` first, then `default` — so priority jobs are always drained before default-queue jobs, even if the worker is busy.

To start additional workers manually during high load:

```bash
php artisan queue:work valkey --queue=priority,default --sleep=3 --tries=3 --max-time=3600
```

### Failed Jobs

Failed jobs (permanently failed after `$tries` exhausted) are stored in the `failed_jobs` table on the `identity` connection (DB 1). Job batches use the `job_batches` table on the same connection.

```bash
php artisan queue:failed           # list failed jobs
php artisan queue:retry {id}       # retry one job by ID
php artisan queue:retry all        # retry all failed jobs
php artisan queue:flush            # clear all failed jobs
```

---

## Job Class Conventions

### Mandatory Structure

```php
<?php

namespace App\Jobs\Lease;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\Lease\LeaseService;
use App\Services\Audit\AuditService;

class ActivateLeaseAfterSignatures implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int   $tries         = 3;
    public int   $maxExceptions = 2;
    public int   $timeout       = 120;
    public array $backoff       = [10, 30, 60];   // retry delay in seconds

    public function __construct(
        public readonly string $leaseId,   // Pass the ID — not the model
    ) {
        $this->onQueue('priority');
    }

    public function handle(LeaseService $leases, AuditService $audit): void
    {
        // Re-fetch from DB inside handle() — don't trust serialized model state
        $lease = \App\Models\Lease\Lease::on('lease')->findOrFail($this->leaseId);

        // Guard against duplicate processing (idempotency)
        if ($lease->status === 'active') {
            return;
        }

        // Do the work
        $leases->activateLease($this->leaseId);
    }

    public function failed(\Throwable $e): void
    {
        \Illuminate\Support\Facades\Log::critical('ActivateLeaseAfterSignatures failed', [
            'lease_id' => $this->leaseId,
            'error'    => $e->getMessage(),
        ]);
        // Alert ops — permanent failure on a priority job requires human review
    }

    public function uniqueId(): string
    {
        return $this->leaseId;  // Prevents duplicate dispatch
    }
}
```

### Non-Negotiable Rules

- **Pass IDs, not models.** Serializing Eloquent models across 14-database architecture is fragile. Pass the UUID and resolve inside `handle()`.
- **Always implement `failed()`.** Every job defines cleanup and alerting for permanent failure.
- **Be idempotent.** A job that runs twice must not double-charge, double-send, or double-create. Check state before acting.
- **Use `ShouldBeUniqueUntilProcessing` or `uniqueId()` for jobs that must not be duplicated.**
- **Write to audit log for any state change.** Any job that mutates domain state calls `AuditService::log()`.
- **Explicit `tries`, `timeout`, `backoff`.** Never rely on framework defaults.
- **Readonly constructor properties.** Use `public readonly string $id` — not plain public properties.

### Dispatching

```php
// Priority queue:
ActivateLeaseAfterSignatures::dispatch($leaseId)->onQueue('priority');

// Or set in constructor (preferred — queue is part of the job's definition):
ActivateLeaseAfterSignatures::dispatch($leaseId);   // uses onQueue('priority') from constructor

// Default queue:
SendEmailNotification::dispatch($userId, $templateKey)->onQueue('default');
SendEmailNotification::dispatch($userId, $templateKey);  // if constructor sets onQueue('default')

// Delayed dispatch:
SendLeaseRenewalReminder::dispatch($leaseId)->delay(now()->addHours(24));

// After commit — dispatch only after the current transaction commits:
SendEmailNotification::dispatch($userId, $templateKey)->afterCommit();
```

---

## Priority Queue Jobs

These jobs use `$this->onQueue('priority')` and have `retry_after = 30s`:

### Safety (`App\Jobs\Safety\`)

| Job | Trigger | Purpose |
|---|---|---|
| `DispatchSosAlert` | SOS button pressed | Emergency alert cascade — SMS, push, email, ops notification |
| `ProcessMissedCheckIn` | Scheduled check-in time passed | Escalation chain for hunter who did not check out |

### Billing (`App\Jobs\Billing\`)

| Job | Trigger | Purpose |
|---|---|---|
| `ProcessStripeWebhook` | Stripe webhook received | Handle payment_intent.succeeded, invoice.paid, etc. |
| `ProcessSubscriptionPayment` | Billing cycle | Charge subscription renewal |
| `ProcessLeasePayment` | Lease payment due | Charge lessee, calculate platform fee, initiate payout |
| `ProcessLandownerPayout` | Payout schedule | Stripe Connect transfer to landowner's bank |
| `HandleFailedPayment` | Payment failure webhook | Grace period logic, retry scheduling, account restriction |

### Documents (`App\Jobs\Documents\`)

| Job | Trigger | Purpose |
|---|---|---|
| `ProcessEsignatureWebhook` | Dropbox Sign webhook | Record signature event, check if all parties signed |
| `ActivateLeaseAfterSignatures` | All required signatures collected | Transition lease to active, provision access info, create lease room |

### Identity (`App\Jobs\Identity\`)

| Job | Trigger | Purpose |
|---|---|---|
| `ProcessOfacScreeningResult` | OFAC webhook | Handle result, update user trust score, flag if hit |

### Commerce (`App\Jobs\Commerce\`)

| Job | Trigger | Purpose |
|---|---|---|
| `CloseAuction` | Auction end time reached | Determine winner, notify parties, initiate lease creation |
| `ProcessAuctionBid` | Bid placed | Validate bid, update high bid in Valkey auction cluster, extend timer if sniping |
| `ConfirmOutfitterBooking` | Booking payment received | Confirm booking, create client thread, send confirmation |
| `ProcessMarketplaceSale` | Sale completed | Transfer funds via Stripe Connect, calculate platform commission |

---

## Default Queue Jobs

These jobs use `$this->onQueue('default')` and have `retry_after = 90s`:

### Notifications (`App\Jobs\Notifications\`)

| Job | Trigger | Purpose |
|---|---|---|
| `SendEmailNotification` | Various events | Email delivery via configured mailer |
| `SendPushNotification` | Various events | FCM / Web Push delivery |
| `SendSmsNotification` | Various events | SMS delivery (Twilio or similar) |
| `SendOutbidNotification` | New high bid on auction | Notify the previous high bidder |

### Lease (`App\Jobs\Lease\`)

| Job | Trigger | Purpose |
|---|---|---|
| `GenerateLeaseAgreement` | Application approved | Build lease PDF from template via Dropbox Sign |
| `SendSignatureRequest` | Lease ready | Dispatch signature request to all required signatories |
| `SendLeaseRenewalReminder` | Scheduled — 90, 30, 7 days before expiry | Renewal notification to lessee and landowner |
| `ExpireLease` | Scheduled — daily | Transition expired active leases to expired status |
| `ArchiveLeaseRoom` | Lease expired + 180 days | Archive message room and communications |

### Identity (`App\Jobs\Identity\`)

| Job | Trigger | Purpose |
|---|---|---|
| `SendEmailVerificationJob` | Signup, email change, resend request | Generate token via `VerificationService`, send `EmailVerificationMail` |
| `SendPasswordResetJob` | Forgot password request | Send `PasswordResetMail` with 1-hour reset link |
| `RunBackgroundCheck` | User request, tier benefit | Submit to Checkr API |
| `ProcessBackgroundCheckResult` | Checkr webhook | Handle returned result, update trust score |
| `ProcessVeteranVerification` | ID.me callback | Grant veteran status and tier benefit |
| `RecalculateTrustScore` | Various events (harvest, reviews, etc.) | Recompute user trust score |
| `PurgeAbandonedSignup` | Scheduled — daily | Remove incomplete signups older than 30 days |

### Property (`App\Jobs\Property\`)

| Job | Trigger | Purpose |
|---|---|---|
| `ProcessPropertyPhoto` | Photo upload | Resize, optimize, generate thumbnails, virus scan |
| `GeneratePropertyMapTiles` | Boundary saved or updated | Pre-render map tiles for property via Mapbox |
| `VerifyPropertyOwnership` | Listing submission | Parcel API check or route to manual review queue |
| `IndexPropertyForSearch` | Publish, update | Update search index |
| `ExpireStaleListing` | Scheduled — daily | Mark inactive listings as expired |

### Wildlife (`App\Jobs\Wildlife\`)

| Job | Trigger | Purpose |
|---|---|---|
| `ProcessHarvestPhoto` | Harvest submitted | Virus scan, optimize, store in object storage |
| `SyncTrailCameraPhotos` | Camera sync event | Ingest batch of trail cam images |
| `TagTrailCameraPhotoWithAi` | Photo ingested | AI species identification and count tagging |
| `ScanHarvestPhotoForVirus` | Photo upload | ClamAV scan before making photo available |
| `UpdateQuotaTracking` | Harvest logged | Decrement species quota, alert landowner if near limit |
| `CheckCwdReporting` | Harvest in CWD zone | Flag harvest for chronic wasting disease reporting |

### Documents (`App\Jobs\Documents\`)

| Job | Trigger | Purpose |
|---|---|---|
| `GenerateLeasePdf` | Various | Render lease agreement PDF |
| `GenerateQrCode` | Gate code issued, ID card created | Generate QR code image |
| `TranscodeVideo` | Property video upload | Transcode to web-compatible format |
| `ScanUploadForVirus` | Any file upload | ClamAV scan before making file available |
| `GenerateDigitalIdCard` | Lease active, tier change | Member digital ID card image |

### Billing (`App\Jobs\Billing\`)

| Job | Trigger | Purpose |
|---|---|---|
| `GenerateInvoice` | Payment event | Create and store invoice PDF |
| `GenerateTax1099Record` | Scheduled — January 15 | 1099-NEC generation per qualifying landowner |
| `ApplyPromotionExpiration` | Scheduled — daily | Convert or downgrade accounts when promo period ends |
| `SendPromoExpirationReminder` | Scheduled — daily | 30/7/1 day reminders before promo expiry |

### Analytics (`App\Jobs\Analytics\`)

These jobs use the `analytics_etl` connection. Never use `analytics` (read-only) from ETL jobs:

| Job | Trigger | Purpose |
|---|---|---|
| `TriggerLeaseMetricsEtl` | Scheduled — nightly | Populate DB 8 lease and revenue metrics |
| `AggregateSubscriptionMetrics` | Scheduled — nightly | MRR, churn rate, conversion metrics |
| `AggregateHarvestData` | Scheduled — nightly | Wildlife reporting rollups |
| `AggregatePromotionPerformance` | Scheduled — nightly | Promo claim and conversion analysis |

### Research (`App\Jobs\Research\`)

These jobs use the `research` connection (DB 14). The only jobs that ever touch DB 14:

| Job | Trigger | Purpose |
|---|---|---|
| `SyncHarvestResearchData` | Scheduled — nightly | Anonymized harvest data export to DB 14 |

### Communications (`App\Jobs\Communications\`)

| Job | Trigger | Purpose |
|---|---|---|
| `ModerateMessage` | Message flagged | Run content moderation checks |
| `SyncDiscordRoles` | Scheduled — every 15 min | Update Discord roles from AH membership status |
| `PostListingToDiscord` | Listing published | Auto-post new listing to community Discord server |

### Platform (`App\Jobs\Platform\`)

| Job | Trigger | Purpose |
|---|---|---|
| `InvalidateEntitlementCache` | Subscription change, promo activation | Clear `user_entitlements:{user_id}` from Valkey |
| `ProcessIotEvent` | Smart lock / IoT webhook | Handle device check-in/access events |

---

## Scheduled Jobs

Scheduled in `routes/console.php` (Laravel 11 style). The scheduler runs inside the app container via Supervisor or a dedicated `php artisan schedule:work` process:

```php
use Illuminate\Support\Facades\Schedule;

// Daily maintenance
Schedule::job(new PurgeAbandonedSignup)->dailyAt('03:00');
Schedule::job(new ApplyPromotionExpiration)->dailyAt('04:00');
Schedule::job(new SendPromoExpirationReminder)->dailyAt('09:00');
Schedule::job(new ExpireStaleListing)->dailyAt('02:00');
Schedule::job(new ExpireLease)->dailyAt('01:00');
Schedule::job(new SendLeaseRenewalReminder)->dailyAt('10:00');

// Nightly ETL (staggered to avoid overlap)
Schedule::job(new TriggerLeaseMetricsEtl)->dailyAt('00:30');
Schedule::job(new AggregateSubscriptionMetrics)->dailyAt('01:30');
Schedule::job(new AggregateHarvestData)->dailyAt('02:30');
Schedule::job(new SyncHarvestResearchData)->dailyAt('03:30');

// High-frequency
Schedule::job(new SyncDiscordRoles)->everyFifteenMinutes();

// Year-end
Schedule::job(new GenerateTax1099Record)->yearlyOn(1, 15, '06:00');  // January 15
```

---

## Failure Handling

### Priority Job Failures

Priority jobs that fail permanently (SOS, payments, payouts, lease activation) alert the ops team immediately via `failed()`:

```php
public function failed(\Throwable $e): void
{
    Log::critical(static::class . ' permanently failed', [
        'job'   => static::class,
        'id'    => $this->leaseId,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);

    // Ops alert — implement via NotificationService or PagerDuty webhook
    app(\App\Services\Platform\AlertService::class)->notifyOps(
        message: static::class . " permanently failed: {$e->getMessage()}",
        context: ['lease_id' => $this->leaseId],
    );
}
```

### Monitoring

Laravel Horizon (optional but recommended for production) provides a real-time dashboard for queue throughput, failure rates, and per-queue metrics. It uses the same `valkey` queue connection.

```bash
composer require laravel/horizon
php artisan horizon:install
php artisan horizon            # start the Horizon process
```

If Horizon is installed, restrict the `/horizon` dashboard to admin users only via `HorizonServiceProvider::gate()`.
