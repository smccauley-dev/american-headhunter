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

Route::middleware('auth.session')->group(function () {
    Route::get('/email/verify', [AuthController::class, 'showVerifyEmailNotice'])->name('auth.verify-email.notice');
    Route::post('/email/verify/resend', [AuthController::class, 'resendVerification'])->name('auth.verify-email.resend');

    Route::get('/mfa/verify', [MfaController::class, 'show'])->name('auth.mfa.verify');
    Route::post('/mfa/verify', [MfaController::class, 'verify'])->name('auth.mfa.verify.submit');

    Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');
});
