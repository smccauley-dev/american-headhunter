<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MfaController;
use App\Http\Controllers\Api\PropertyController;
use App\Http\Controllers\Api\RecoveryController;
use Illuminate\Support\Facades\Route;

$propertyRoutes = function () {
    Route::get('/', [PropertyController::class, 'index']);
    Route::get('/{id}/boundary', [PropertyController::class, 'boundary']);
    Route::get('/{id}', [PropertyController::class, 'show']);
};

// v1 property routes — auth + hunter:read ability required
Route::prefix('v1/properties')
    ->middleware(['auth:sanctum', 'abilities:hunter:read'])
    ->group($propertyRoutes);

// Legacy property routes — no auth, backward-compat for web app
Route::prefix('properties')->group($propertyRoutes);

// Auth — login, MFA challenge verification, recovery, logout
Route::prefix('v1/auth')->group(function () {
    Route::post('/login',      [AuthController::class, 'login']);
    Route::post('/mfa/send',   [AuthController::class, 'mfaSend'])->middleware('throttle:mfa-send');
    Route::post('/mfa/verify', [AuthController::class, 'mfaVerify'])->middleware('throttle:mfa-verify');
    // Recovery uses its own stricter bucket — wrong recovery code is a stronger attack signal
    Route::post('/mfa/recover', [RecoveryController::class, 'recover'])->middleware('throttle:mfa-recover');
    Route::post('/logout',     [AuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::post('/revoke-all', [AuthController::class, 'revokeAll'])->middleware('auth:sanctum');
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
