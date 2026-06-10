<?php

use App\Http\Controllers\Admin\PrintApplicationController;
use App\Http\Controllers\Apply\ApplyController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Member\LeaseSignController;
use App\Http\Controllers\Member\MemberController;
use App\Http\Controllers\Member\ProfileController;
use App\Http\Controllers\Api\MentionController;
use App\Http\Controllers\Member\SecurityController;
use App\Http\Controllers\Public\HunterPublicProfileController;
use App\Http\Controllers\Public\PropertyController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::get('/properties', [PropertyController::class, 'index'])->name('property.index');
Route::get('/properties/{slug}', [PropertyController::class, 'show'])->name('property.show');

Route::get('/hunters/{username}', [HunterPublicProfileController::class, 'show'])->name('hunter.public');

// Public API — rate-limited, no auth required
Route::prefix('api')->name('api.')->group(function () {
    Route::get('/mention/{username}', [MentionController::class, 'show'])
        ->name('mention')
        ->middleware('throttle:60,1');
});

// Application portal — all routes require authentication
// Order matters: specific paths before /{listing} wildcard
Route::middleware('auth.session')->prefix('apply')->name('apply.')->group(function () {
    Route::get('/my-applications', [ApplyController::class, 'index'])->name('index');
    Route::get('/status/{application}', [ApplyController::class, 'status'])->name('status');
    Route::post('/status/{application}/message', [ApplyController::class, 'sendMessage'])->name('status.message')->middleware('throttle:10,1');
    Route::get('/{listing}', [ApplyController::class, 'show'])->name('show');
    Route::post('/{listing}', [ApplyController::class, 'submit'])->name('submit')->middleware('throttle:5,1');
});

// Admin print views — protected by Filament web guard
Route::middleware('auth:web')->get('/admin/applications/{application}/print', [PrintApplicationController::class, 'show'])->name('admin.applications.print');

// Member portal
Route::middleware('auth.session')->prefix('member')->name('member.')->group(function () {
    Route::get('/', [MemberController::class, 'dashboard'])->name('dashboard');
    Route::get('/leases/{lease}', [MemberController::class, 'show'])->name('leases.show');
    Route::get('/leases/{lease}/sign', [LeaseSignController::class, 'show'])->name('leases.sign');
    Route::post('/leases/{lease}/sign', [LeaseSignController::class, 'sign'])->name('leases.sign.submit');

    Route::get('/profile/avatar/{userId}', [ProfileController::class, 'serveAvatar'])->name('profile.avatar');
    Route::get('/profile/photos/{documentId}', [ProfileController::class, 'servePhoto'])->name('profile.photos.serve');
    Route::get('/profile', [ProfileController::class, 'show'])->name('profile');
    Route::post('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::post('/profile/avatar', [ProfileController::class, 'uploadAvatar'])->name('profile.avatar.upload');
    Route::post('/profile/photos', [ProfileController::class, 'uploadPhoto'])->name('profile.photos.upload');
    Route::delete('/profile/photos/{documentId}', [ProfileController::class, 'deletePhoto'])->name('profile.photos.delete');

    Route::post('/security/password',                [SecurityController::class, 'changePassword'])->name('security.password')->middleware('throttle:5,1');
    Route::post('/security/mfa/{method}/enable',     [SecurityController::class, 'enableMfa'])->name('security.mfa.enable');
    Route::post('/security/mfa/{method}/disable',    [SecurityController::class, 'disableMfa'])->name('security.mfa.disable');
    Route::post('/security/profile-visibility',      [SecurityController::class, 'setProfileVisibility'])->name('security.profile.visibility');
    Route::get('/security/username-check/{username}', [SecurityController::class, 'checkUsername'])->name('security.username.check')->middleware('throttle:30,1');
});

require __DIR__ . '/auth.php';
