<?php

use App\Jobs\Billing\ReconcileStripeInvoices;
use App\Jobs\Billing\ReleaseEndedLeaseDeposits;
use App\Jobs\Documents\CleanupStagedUploads;
use App\Jobs\Documents\CleanupUnattachedDocuments;
use App\Jobs\Etl\SyncPlatformSnapshot;
use App\Jobs\ExpireListingsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Auto-expire listings whose season_end has passed. Runs nightly at 00:30.
Schedule::job(new ExpireListingsJob)->dailyAt('00:30');

// Remove unattached documents (created by in-flight apply submissions that never committed).
// Threshold is DOCUMENT_REAPER_TTL_MINUTES (default 120 min). Runs every two hours.
Schedule::job(new CleanupUnattachedDocuments)->everyTwoHours();

// Sweep abandoned FilePond staging files (ownership proof / photos / map images) left by
// uploads that were never submitted. Threshold is STAGED_UPLOAD_TTL_MINUTES (default
// 1440 min / 24h). Runs hourly — the layer before CleanupUnattachedDocuments.
Schedule::job(new CleanupStagedUploads)->hourly();

// Purge consumed and long-expired MFA challenge rows (SEC-041).
// Runs at 03:00 daily — off-peak, after the 00:30 listing expiry job.
Schedule::command('mfa:prune-challenges')->dailyAt('03:00');

// Hard-delete lease documents soft-deleted more than 30 days ago and clean up storage files.
// Runs at 02:00 daily — between listing expiry and MFA pruning.
Schedule::command('lease:prune-deleted-documents')->dailyAt('02:00');

// Reconcile the Stripe invoice projection (Phase 5.7) — backstop for any missed
// webhook. Runs at 04:00 daily, after the other maintenance jobs.
Schedule::job(new ReconcileStripeInvoices)->dailyAt('04:00');

// Auto-release security deposits whose lease ended more than the grace window ago
// (default 14 days), honoring the "returned at lease end" promise without manual
// admin action. Normal endings only — terminated/cancelled leases are left for an
// admin. Runs at 05:00 daily, after the invoice reconcile.
Schedule::job(new ReleaseEndedLeaseDeposits)->dailyAt('05:00');

// Recompute the platform analytics rollups (DB 8) that feed the admin dashboard
// and public homepage stats. Hourly; the dashboard "Refresh now" button runs the
// same job on demand.
Schedule::job(new SyncPlatformSnapshot)->hourly();
