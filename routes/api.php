<?php

use App\Http\Controllers\Api\ApplicationController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CheckInController;
use App\Http\Controllers\Api\CwdController;
use App\Http\Controllers\Api\DropboxSignWebhookController;
use App\Http\Controllers\Api\FishingController;
use App\Http\Controllers\Api\HarvestController;
use App\Http\Controllers\Api\LeaseSigningController;
use App\Http\Controllers\Api\MfaController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PasswordController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\PropertyContactController;
use App\Http\Controllers\Api\PropertyController;
use App\Http\Controllers\Api\PropertyMapController;
use App\Http\Controllers\Api\RecoveryController;
use App\Http\Controllers\Api\SightingController;
use App\Http\Controllers\Api\StripeWebhookController;
use App\Http\Controllers\Api\TrailCameraController;
use Illuminate\Support\Facades\Route;

$propertyRoutes = function () {
    Route::get('/', [PropertyController::class, 'index']);
    Route::get('/{id}/boundary', [PropertyController::class, 'boundary']);
    Route::get('/{id}', [PropertyController::class, 'show']);
};

// v1 property routes — auth + hunter:read ability required (throttled, SEC-008)
Route::prefix('v1/properties')
    ->middleware(['auth:sanctum', 'abilities:hunter:read', 'throttle:api'])
    ->group(function () use ($propertyRoutes) {
        $propertyRoutes();

        // Member field-ops map data — additionally gated to active lessees
        // inside the controller (markers carry precise GPS, see SEC-024).
        Route::get('/{id}/map', [PropertyMapController::class, 'show']);
        Route::get('/{id}/map-images/{documentId}', [PropertyMapController::class, 'image'])
            ->name('api.property-maps.image');

        // Member contact directory — landowner, managers, local/emergency
        // contacts. Gated to active lessees inside the controller (SEC-024).
        Route::get('/{id}/contacts', [PropertyContactController::class, 'index']);
    });

// Legacy property routes — no auth, backward-compat for web app (per-IP throttle, SEC-008)
Route::prefix('properties')->middleware('throttle:public-api')->group($propertyRoutes);

// Auth — registration, login, MFA challenge verification, recovery, logout
// SEC-043: pre-context auth bootstrap runs as the trusted ah_system role.
Route::prefix('v1/auth')->middleware('db.system')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:6,1');
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/mfa/send', [AuthController::class, 'mfaSend'])->middleware('throttle:mfa-send');
    Route::post('/mfa/verify', [AuthController::class, 'mfaVerify'])->middleware('throttle:mfa-verify');
    // Recovery uses its own stricter bucket — wrong recovery code is a stronger attack signal
    Route::post('/mfa/recover', [RecoveryController::class, 'recover'])->middleware('throttle:mfa-recover');
    // Password recovery — always-200 request, token-verified reset (throttled).
    Route::post('/password/forgot', [PasswordController::class, 'forgot'])->middleware('throttle:6,1');
    Route::post('/password/reset', [PasswordController::class, 'reset'])->middleware('throttle:6,1');
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::post('/revoke-all', [AuthController::class, 'revokeAll'])->middleware('auth:sanctum');
});

// Member profile — read/update own identity + hunting core, avatar. Runs as the
// Sanctum member (ah_runtime); RLS scopes every query to the caller.
Route::prefix('v1/profile')
    ->middleware(['auth:sanctum', 'abilities:hunter:read', 'throttle:api'])
    ->group(function () {
        Route::get('/', [ProfileController::class, 'show']);
        Route::put('/', [ProfileController::class, 'update']);
        Route::get('/avatar', [ProfileController::class, 'serveAvatar']);
        Route::post('/avatar', [ProfileController::class, 'uploadAvatar'])->middleware('throttle:10,1');
    });

// Field check-in / check-out — mobile API. Runs as the Sanctum member
// (ah_runtime); standing to check in against a lease is enforced inside
// CheckInService (403 for non-lessee/non-approved-hunter). GPS is advisory.
Route::middleware(['auth:sanctum', 'abilities:hunter:checkin', 'throttle:api'])->group(function () {
    Route::get('/v1/checkins/active', [CheckInController::class, 'active']);
    Route::post('/v1/leases/{lease}/checkin', [CheckInController::class, 'checkIn'])->middleware('throttle:20,1');
    Route::post('/v1/leases/{lease}/checkout', [CheckInController::class, 'checkOut'])->middleware('throttle:20,1');
});

// Lease applications — mobile API. Runs as the Sanctum member (ah_runtime);
// every query is scoped to the caller's own applications. The primary hunter is
// drawn from the caller's saved credentials (roster capture stays on the web).
Route::middleware(['auth:sanctum', 'abilities:hunter:apply', 'throttle:api'])->group(function () {
    Route::get('/v1/applications', [ApplicationController::class, 'index']);
    Route::get('/v1/applications/{application}', [ApplicationController::class, 'show']);
    Route::post('/v1/listings/{listing}/apply', [ApplicationController::class, 'apply'])->middleware('throttle:10,1');
    Route::post('/v1/applications/{application}/withdraw', [ApplicationController::class, 'withdraw'])->middleware('throttle:10,1');
});

// Wildlife field operations — mobile API. DB 5 has NO row-level security: the
// WildlifeAccess standing check inside each service is the entire authorization
// boundary, and the controllers re-enforce it on every call. Reads use
// hunter:read; field-log writes use hunter:harvest and are idempotent on a
// client-minted local_record_id (the offline sync queue) with a sync-friendly
// throttle for a reconnect flush. Quota (409) and CWD ack (422) are re-checked
// authoritatively server-side at sync, never trusted from the offline client.
Route::prefix('v1')
    ->middleware(['auth:sanctum', 'throttle:api'])
    ->group(function () {
        // Harvest logs + quota
        Route::get('/harvests', [HarvestController::class, 'index'])->middleware('abilities:hunter:read');
        Route::get('/harvests/{harvest}', [HarvestController::class, 'show'])->middleware('abilities:hunter:read');
        Route::post('/leases/{lease}/harvests', [HarvestController::class, 'store'])
            ->middleware(['abilities:hunter:harvest', 'throttle:30,1']);
        Route::get('/leases/{lease}/quota', [HarvestController::class, 'quota'])->middleware('abilities:hunter:read');

        // CWD reference data (offline cache + ack prompt)
        Route::get('/cwd/zones', [CwdController::class, 'zones'])->middleware('abilities:hunter:read');

        // Wildlife sightings
        Route::get('/leases/{lease}/sightings', [SightingController::class, 'index'])->middleware('abilities:hunter:read');
        Route::post('/leases/{lease}/sightings', [SightingController::class, 'store'])
            ->middleware(['abilities:hunter:harvest', 'throttle:30,1']);

        // Fishing catches
        Route::get('/leases/{lease}/fishing', [FishingController::class, 'index'])->middleware('abilities:hunter:read');
        Route::post('/leases/{lease}/fishing', [FishingController::class, 'store'])
            ->middleware(['abilities:hunter:harvest', 'throttle:30,1']);

        // Trail cameras — additionally gated on the trail_camera_integration
        // entitlement inside TrailCameraService (403 when the plan excludes it).
        Route::get('/properties/{property}/cameras', [TrailCameraController::class, 'index'])->middleware('abilities:hunter:read');
        Route::get('/cameras/{camera}/photos', [TrailCameraController::class, 'photos'])->middleware('abilities:hunter:read');
    });

// Dropbox Sign webhook — no auth, HMAC-verified internally
Route::post('/webhooks/dropbox-sign', [DropboxSignWebhookController::class, 'handle'])
    ->name('webhooks.dropbox-sign');

// Stripe webhook — no auth, signature-verified internally; dispatches to the
// priority queue where the worker runs under the trusted ah_system role.
Route::post('/webhooks/stripe', [StripeWebhookController::class, 'handle'])
    ->name('webhooks.stripe');

// Lease signing — mobile API
Route::prefix('v1/leases')
    ->middleware(['auth:sanctum'])
    ->group(function () {
        Route::get('/', [LeaseSigningController::class, 'index']);
        Route::get('/{id}', [LeaseSigningController::class, 'show']);
        Route::get('/{id}/signing-url', [LeaseSigningController::class, 'signingUrl']);
        Route::get('/{id}/signature-status', [LeaseSigningController::class, 'signatureStatus']);
        Route::get('/{id}/contract', [LeaseSigningController::class, 'contract'])
            ->name('api.leases.contract.download');
    });

// Notification center — mobile API parity with the member portal "bell".
// Reads + mark-read run as the Sanctum member (ah_runtime); RLS scopes every
// query to the caller. No db.system — creation is system-authored elsewhere.
Route::prefix('v1/notifications')
    ->middleware(['auth:sanctum', 'abilities:hunter:read', 'throttle:api'])
    ->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('/read-all', [NotificationController::class, 'markAllRead']);
        Route::post('/{notification}/read', [NotificationController::class, 'markRead']);
    });

// MFA enrollment management — requires active hunter token
Route::prefix('v1/mfa')
    ->middleware(['auth:sanctum', 'abilities:hunter:read'])
    ->group(function () {
        Route::get('/', [MfaController::class, 'list']);
        Route::post('/enroll/{method}', [MfaController::class, 'enroll']);
        Route::post('/confirm/{method}', [MfaController::class, 'confirm']);
        Route::delete('/{method}', [MfaController::class, 'disable']);
        Route::post('/recovery-codes/regenerate', [MfaController::class, 'regenerate']);
    });
