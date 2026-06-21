<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\MfaController;
use App\Http\Controllers\Auth\PasswordController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/get-started', [AuthController::class, 'getStarted'])->name('auth.get-started');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('auth.register');
    Route::post('/register', [AuthController::class, 'register'])->name('auth.register.submit');

    Route::get('/login', [AuthController::class, 'showLogin'])->name('auth.login');
    Route::post('/login', [AuthController::class, 'login'])->name('auth.login.submit');

    Route::get('/forgot-password', [PasswordController::class, 'showForgot'])->name('auth.password.forgot');
    Route::post('/forgot-password', [PasswordController::class, 'sendReset'])->name('auth.password.email');
    Route::get('/reset-password/{token}', [PasswordController::class, 'showReset'])->name('auth.password.reset');
    Route::post('/reset-password', [PasswordController::class, 'reset'])->name('auth.password.update');
});

// Email verification works with or without a session (user may click link from different device)
Route::get('/email/verify/{token}', [AuthController::class, 'verifyEmail'])->name('auth.verify-email');

// MFA verification runs AFTER password but BEFORE the session is authenticated
// (auth.user_id is only set once a code is verified — pending state lives in
// Valkey). It must NOT be inside the auth.session group, or RequireSessionAuth
// bounces every pending user straight back to /login. The controller enforces
// the mfa-pending check instead.
Route::get('/mfa/verify', [MfaController::class, 'show'])->name('auth.mfa.verify');
Route::post('/mfa/verify', [MfaController::class, 'verify'])->name('auth.mfa.verify.submit')->middleware('throttle:10,1');
Route::post('/mfa/resend', [MfaController::class, 'resend'])->name('auth.mfa.resend')->middleware('throttle:3,1');

// allow-pending: this is the post-signup waiting room, so a pending_verification
// account (not yet active) must be able to see the notice, resend, and log out.
Route::middleware('auth.session:allow-pending')->group(function () {
    Route::get('/email/verify', [AuthController::class, 'showVerifyEmailNotice'])->name('auth.verify-email.notice');
    // Polled by the notice screen so it advances on its own once the link is
    // clicked (possibly on another device). Distinct path so it isn't captured
    // by the /email/verify/{token} wildcard above.
    Route::get('/email/verification-status', [AuthController::class, 'verifyEmailStatus'])->name('auth.verify-email.status');
    Route::post('/email/verify/resend', [AuthController::class, 'resendVerification'])->name('auth.verify-email.resend');

    Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');
});
