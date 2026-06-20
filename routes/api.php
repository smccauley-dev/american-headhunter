<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DropboxSignWebhookController;
use App\Http\Controllers\Api\LeaseSigningController;
use App\Http\Controllers\Api\MfaController;
use App\Http\Controllers\Api\PropertyContactController;
use App\Http\Controllers\Api\PropertyController;
use App\Http\Controllers\Api\PropertyMapController;
use App\Http\Controllers\Api\RecoveryController;
use App\Http\Controllers\Api\StripeWebhookController;
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

// Auth — login, MFA challenge verification, recovery, logout
// SEC-043: pre-context auth bootstrap runs as the trusted ah_system role.
Route::prefix('v1/auth')->middleware('db.system')->group(function () {
    Route::post('/login',      [AuthController::class, 'login']);
    Route::post('/mfa/send',   [AuthController::class, 'mfaSend'])->middleware('throttle:mfa-send');
    Route::post('/mfa/verify', [AuthController::class, 'mfaVerify'])->middleware('throttle:mfa-verify');
    // Recovery uses its own stricter bucket — wrong recovery code is a stronger attack signal
    Route::post('/mfa/recover', [RecoveryController::class, 'recover'])->middleware('throttle:mfa-recover');
    Route::post('/logout',     [AuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::post('/revoke-all', [AuthController::class, 'revokeAll'])->middleware('auth:sanctum');
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
        Route::get('/',                     [LeaseSigningController::class, 'index']);
        Route::get('/{id}',                 [LeaseSigningController::class, 'show']);
        Route::get('/{id}/signing-url',     [LeaseSigningController::class, 'signingUrl']);
        Route::get('/{id}/signature-status', [LeaseSigningController::class, 'signatureStatus']);
        Route::get('/{id}/contract',        [LeaseSigningController::class, 'contract'])
            ->name('api.leases.contract.download');
    });

// MFA enrollment management — requires active hunter token
Route::prefix('v1/mfa')
    ->middleware(['auth:sanctum', 'abilities:hunter:read'])
    ->group(function () {
        Route::get('/',                           [MfaController::class, 'list']);
        Route::post('/enroll/{method}',           [MfaController::class, 'enroll']);
        Route::post('/confirm/{method}',          [MfaController::class, 'confirm']);
        Route::delete('/{method}',                [MfaController::class, 'disable']);
        Route::post('/recovery-codes/regenerate', [MfaController::class, 'regenerate']);
    });
