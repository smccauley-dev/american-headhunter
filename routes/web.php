<?php

use App\Http\Controllers\Admin\PrintApplicationController;
use App\Http\Controllers\Apply\ApplyController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Member\CheckInController;
use App\Http\Controllers\Member\CheckoutController;
use App\Http\Controllers\Member\LeaseApplicationController as MemberLeaseApplicationController;
use App\Http\Controllers\Member\LeaseDocumentController;
use App\Http\Controllers\Member\LeaseSignController;
use App\Http\Controllers\Member\MemberController;
use App\Http\Controllers\Member\MembershipController;
use App\Http\Controllers\Member\PayoutController;
use App\Http\Controllers\Member\ProfileController;
use App\Http\Controllers\Member\PropertyController as MemberPropertyController;
use App\Http\Controllers\Member\PropertyDetailController as MemberPropertyDetailController;
use App\Http\Controllers\Member\PropertyListingController as MemberPropertyListingController;
use App\Http\Controllers\Member\PropertyManagerController as MemberPropertyManagerController;
use App\Http\Controllers\Member\PropertyPhotoController as MemberPropertyPhotoController;
use App\Http\Controllers\Member\PropertyMapController as MemberPropertyMapController;
use App\Http\Controllers\Member\PropertyOwnershipController as MemberPropertyOwnershipController;
use App\Http\Controllers\Member\PropertyContactController as MemberPropertyContactController;
use App\Http\Controllers\Api\MentionController;
use App\Http\Controllers\Member\SecurityController;
use App\Http\Controllers\Public\HunterPublicProfileController;
use App\Http\Controllers\Public\PricingController;
use App\Http\Controllers\Public\PropertyController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::get('/properties', [PropertyController::class, 'index'])->name('property.index');
Route::get('/properties/{slug}', [PropertyController::class, 'show'])->name('property.show');

Route::get('/pricing', [PricingController::class, 'index'])->name('pricing.index');

Route::get('/hunters/{username}', [HunterPublicProfileController::class, 'show'])->name('hunter.public');

// Gate check-in QR — public landing (prompts login) and the rendered QR image.
Route::get('/checkin/{token}', [CheckInController::class, 'scan'])->name('checkin.scan');
Route::get('/qr/checkin/{token}.png', [CheckInController::class, 'png'])->name('checkin.qr.png');

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

// Admin routes that use Laravel's `web` guard (Filament staff auth) but live
// OUTSIDE the admin panel's middleware. SEC-043: they must run as the trusted
// ah_system (BYPASSRLS) role. The web guard resolves the staff user via an
// RLS-protected SELECT on identity.users, which returns 0 rows under ah_runtime
// (the Laravel guard sets no per-user RLS context). Without db.system the guard
// finds no user and Authenticate redirects to a `login` route that doesn't exist
// here (Filament uses filament.admin.auth.login) → 500 / broken images/files.
// Keep ALL admin web-guard routes in this group so the role can never be omitted.
// db.system is registered in the middleware priority list before the auth
// contract (bootstrap/app.php) so it always runs before the guard resolves.
Route::middleware(['db.system', 'auth:web'])->group(function () {
    // Admin print views
    Route::get('/admin/applications/{application}/print', [PrintApplicationController::class, 'show'])->name('admin.applications.print');

    // Admin lease-document restore (undo soft-delete)
    Route::post('/admin/lease-documents/{leaseDocumentId}/restore', function (string $leaseDocumentId) {
        app(\App\Services\Lease\LeaseDocumentService::class)->restore($leaseDocumentId, auth()->id());
        return back();
    })->name('admin.lease-documents.restore');

    // Admin lease-document soft-delete
    Route::post('/admin/lease-documents/{leaseDocumentId}/delete', function (string $leaseDocumentId) {
        app(\App\Services\Lease\LeaseDocumentService::class)->remove($leaseDocumentId, auth()->id());
        return back();
    })->name('admin.lease-documents.delete');

    // Admin lease-document download (from lease_documents table, with audit logging)
    Route::get('/admin/lease-documents/{leaseDocumentId}/download', function (string $leaseDocumentId) {
        return app(\App\Services\Lease\LeaseDocumentService::class)->adminDownload(
            $leaseDocumentId,
            auth()->id(),
        );
    })->name('admin.lease-documents.download');

    // Admin document download / inline view. SEC-050: routed through a controller
    // that audit-logs every access — these serve applicant PII (DL/license images
    // in the hunter roster). Staff-only via the web guard (canAccessPanel).
    Route::get('/admin/documents/{documentId}/download', [\App\Http\Controllers\Admin\AdminDocumentController::class, 'download'])
        ->name('admin.documents.download');
    Route::get('/admin/documents/{documentId}/view', [\App\Http\Controllers\Admin\AdminDocumentController::class, 'view'])
        ->name('admin.documents.view');
});

// Public property photo — only documents referenced by a live property_photos
// row are served; everything else in the documents store stays private.
Route::get('/property-photos/{documentId}', function (string $documentId) {
    $isPropertyPhoto = \Illuminate\Support\Facades\DB::connection('property')
        ->table('property_photos')
        ->where('document_id', $documentId)
        ->whereNull('deleted_at')
        ->exists();
    abort_unless($isPropertyPhoto, 404);

    $doc  = \App\Models\Documents\Document::on('documents')->findOrFail($documentId);
    $disk = \Illuminate\Support\Facades\Storage::disk(config('filesystems.defaults.documents', 'local'));
    abort_unless($disk->exists($doc->storage_key), 404);

    return $disk->response(
        $doc->storage_key,
        $doc->original_filename,
        ['Content-Type' => $doc->mime_type ?? 'image/jpeg', 'Cache-Control' => 'public, max-age=86400'],
    );
})->name('property-photos.show');

// Public boundary map image — only the live boundary map per property is
// served publicly; other map images remain behind the admin guard.
Route::get('/property-maps/{documentId}', function (string $documentId) {
    // Only the boundary map of a live, active property is public — draft,
    // suspended, archived and soft-deleted properties stay private (SEC-025).
    $isBoundaryMap = \Illuminate\Support\Facades\DB::connection('property')
        ->table('property_map_images as pmi')
        ->join('properties as p', 'p.id', '=', 'pmi.property_id')
        ->where('pmi.document_id', $documentId)
        ->where('pmi.is_boundary', true)
        ->whereNull('pmi.deleted_at')
        ->where('p.status', 'active')
        ->whereNull('p.deleted_at')
        ->exists();
    abort_unless($isBoundaryMap, 404);

    $doc  = \App\Models\Documents\Document::on('documents')->findOrFail($documentId);
    $disk = \Illuminate\Support\Facades\Storage::disk(config('filesystems.defaults.documents', 'local'));
    abort_unless($disk->exists($doc->storage_key), 404);

    return $disk->response(
        $doc->storage_key,
        $doc->original_filename,
        ['Content-Type' => $doc->mime_type ?? 'image/jpeg', 'Cache-Control' => 'public, max-age=3600'],
    );
})->name('property-maps.show');

// Member portal
Route::middleware('auth.session')->prefix('member')->name('member.')->group(function () {
    Route::get('/', [MemberController::class, 'dashboard'])->name('dashboard');
    Route::get('/leases/{lease}', [MemberController::class, 'show'])->name('leases.show');
    Route::get('/leases/{lease}/sign', [LeaseSignController::class, 'show'])->name('leases.sign');
    Route::post('/leases/{lease}/sign', [LeaseSignController::class, 'sign'])->name('leases.sign.submit');
    Route::get('/leases/{lease}/signed', [LeaseSignController::class, 'downloadSigned'])->name('leases.signed.download');
    Route::get('/leases/{lease}/esign-documents/{document}/download', [LeaseSignController::class, 'downloadEsignDocument'])->name('leases.esign.download');
    Route::post('/leases/{lease}/documents', [LeaseDocumentController::class, 'upload'])->name('leases.documents.upload')->middleware('throttle:20,1');
    Route::get('/leases/{lease}/documents/{leaseDocument}/download', [LeaseDocumentController::class, 'download'])->name('leases.documents.download');
    Route::delete('/leases/{lease}/documents/{leaseDocument}', [LeaseDocumentController::class, 'destroy'])->name('leases.documents.destroy');
    Route::post('/leases/{lease}/messages', [MemberController::class, 'message'])->name('leases.messages.store')->middleware('throttle:20,1');
    Route::post('/leases/{lease}/deposit', [MemberController::class, 'payDeposit'])->name('leases.deposit')->middleware('throttle:10,1');
    // Stripe deposit success return — reconciles the held row as ah_system (the row
    // is system-authored; the runtime member role cannot write security_deposits).
    Route::get('/leases/{lease}/deposit/return', [MemberController::class, 'depositReturn'])->name('leases.deposit.return')->middleware('db.system');
    // Forfeiture contest + insurance opt-out (lessee), and damage-claim intake
    // (lessor). All author DB-10/DB-4 system records, so they run as ah_system
    // (db.system, BYPASSRLS); evidence is uploaded multipart.
    Route::post('/leases/{lease}/forfeiture/contest', [MemberController::class, 'contestForfeiture'])->name('leases.forfeiture.contest')->middleware(['db.system', 'throttle:10,1']);
    Route::post('/leases/{lease}/forfeiture/opt-out', [MemberController::class, 'optOutForfeiture'])->name('leases.forfeiture.opt-out')->middleware(['db.system', 'throttle:10,1']);
    Route::post('/leases/{lease}/damage-claims', [MemberController::class, 'fileDamageClaim'])->name('leases.damage-claims.store')->middleware(['db.system', 'throttle:10,1']);
    Route::post('/leases/{lease}/incidents', [MemberController::class, 'reportIncident'])->name('leases.incidents.store')->middleware(['db.system', 'throttle:10,1']);
    // Reporter edits their own incident (e.g. correcting a mistake). System-authored
    // (db.system, BYPASSRLS) — every edit is diff-audited; added photos are appended
    // (existing evidence can never be removed). Multipart for the optional new photos.
    Route::post('/leases/{lease}/incidents/{incident}', [MemberController::class, 'updateIncident'])->name('leases.incidents.update')->middleware(['db.system', 'throttle:10,1']);
    // Serve an incident's evidence photo to the reporter (RLS scopes the read to them).
    Route::get('/leases/{lease}/incidents/{incident}/photos/{documentId}', [MemberController::class, 'incidentPhoto'])->name('leases.incident-photo');

    Route::post('/leases/{lease}/booking-deposit', [MemberController::class, 'payBookingDeposit'])->name('leases.booking-deposit')->middleware('throttle:10,1');
    // Stripe booking-deposit success return — reconciles the collected row as
    // ah_system (booking_deposits is system-authored; ah_runtime cannot write it).
    Route::get('/leases/{lease}/booking-deposit/return', [MemberController::class, 'bookingDepositReturn'])->name('leases.booking-deposit.return')->middleware('db.system');

    Route::post('/checkin',  [CheckInController::class, 'store'])->name('checkin.store')->middleware('throttle:20,1');
    Route::post('/checkout', [CheckInController::class, 'destroy'])->name('checkin.destroy')->middleware('throttle:20,1');
    Route::post('/leases/{lease}/email-qr', [CheckInController::class, 'emailQr'])->name('leases.email-qr')->middleware('throttle:5,1');

    Route::get('/profile/avatar/{userId}', [ProfileController::class, 'serveAvatar'])->name('profile.avatar');
    Route::get('/profile/photos/{documentId}', [ProfileController::class, 'servePhoto'])->name('profile.photos.serve');
    Route::get('/profile', [ProfileController::class, 'show'])->name('profile');
    Route::get('/myleases', [ProfileController::class, 'show'])->defaults('initialTab', 'leases')->name('myleases');
    Route::get('/membership', [ProfileController::class, 'show'])->defaults('initialTab', 'membership')->name('membership');
    Route::post('/membership/checkout', [CheckoutController::class, 'create'])->name('membership.checkout')->middleware('throttle:10,1');
    Route::post('/membership/cancel', [MembershipController::class, 'cancel'])->name('membership.cancel')->middleware('throttle:10,1');
    Route::post('/membership/resume', [MembershipController::class, 'resume'])->name('membership.resume')->middleware('throttle:10,1');
    Route::post('/membership/change', [MembershipController::class, 'changePlan'])->name('membership.change')->middleware('throttle:10,1');
    Route::post('/membership/update-payment', [MembershipController::class, 'updatePayment'])->name('membership.update-payment')->middleware('throttle:10,1');

    // Landowner Stripe Connect onboarding. connect/refresh create the Connect
    // account row (a stripe_accounts write) so they run as ah_system (db.system,
    // SEC-055); return only reads + redirects. The account.updated webhook syncs
    // the authoritative payouts_enabled flag.
    Route::middleware('db.system')->group(function () {
        Route::post('/payouts/connect', [PayoutController::class, 'connect'])->name('payouts.connect')->middleware('throttle:10,1');
        Route::get('/payouts/refresh',  [PayoutController::class, 'refresh'])->name('payouts.refresh');
    });
    Route::get('/payouts/return', [PayoutController::class, 'return'])->name('payouts.return');
    Route::post('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::post('/profile/avatar', [ProfileController::class, 'uploadAvatar'])->name('profile.avatar.upload');
    Route::post('/profile/photos', [ProfileController::class, 'uploadPhoto'])->name('profile.photos.upload');
    Route::post('/profile/photos/reorder', [ProfileController::class, 'reorderPhotos'])->name('profile.photos.reorder');
    Route::patch('/profile/photos/{documentId}', [ProfileController::class, 'updatePhoto'])->name('profile.photos.update');
    Route::delete('/profile/photos/{documentId}', [ProfileController::class, 'deletePhoto'])->name('profile.photos.delete');

    // Landowner front-end property management. 'create' is declared before the
    // '{property}' wildcard so it is not captured as a property id.
    Route::get('/properties/create',     [MemberPropertyController::class, 'create'])->name('properties.create');
    Route::post('/properties',           [MemberPropertyController::class, 'store'])->name('properties.store');
    Route::get('/properties/{property}', [MemberPropertyController::class, 'edit'])->name('properties.edit');
    Route::put('/properties/{property}', [MemberPropertyController::class, 'update'])->name('properties.update');

    // Proof of ownership / management — gates the property going Active. The /temp
    // routes (FilePond instant-upload + revert) are declared before the document
    // serve route so neither captures the other.
    Route::post('/properties/{property}/ownership/temp',                  [MemberPropertyOwnershipController::class, 'tempStore'])->name('properties.ownership.temp.store')->middleware('throttle:60,1');
    Route::delete('/properties/{property}/ownership/temp',                [MemberPropertyOwnershipController::class, 'tempRevert'])->name('properties.ownership.temp.revert')->middleware('throttle:60,1');
    Route::post('/properties/{property}/ownership',                       [MemberPropertyOwnershipController::class, 'store'])->name('properties.ownership.store')->middleware('throttle:20,1');
    Route::get('/properties/{property}/ownership/documents/{documentId}', [MemberPropertyOwnershipController::class, 'serveDocument'])->name('properties.ownership.document');

    // Property details (game types, rules, amenities) nested under a property.
    Route::get('/properties/{property}/details', [MemberPropertyDetailController::class, 'edit'])->name('properties.details.edit');
    Route::put('/properties/{property}/details', [MemberPropertyDetailController::class, 'update'])->name('properties.details.update');

    // Listings nested under a property.
    Route::get('/properties/{property}/listings',              [MemberPropertyListingController::class, 'index'])->name('properties.listings.index');
    Route::post('/properties/{property}/listings',             [MemberPropertyListingController::class, 'store'])->name('properties.listings.store');
    Route::put('/properties/{property}/listings/{listing}',    [MemberPropertyListingController::class, 'update'])->name('properties.listings.update');
    Route::delete('/properties/{property}/listings/{listing}', [MemberPropertyListingController::class, 'destroy'])->name('properties.listings.destroy');

    // Day-hunt availability calendar for a listing (view + manage blackouts).
    Route::get('/properties/{property}/listings/{listing}/availability',  [MemberPropertyListingController::class, 'availability'])->name('properties.listings.availability');
    Route::put('/properties/{property}/listings/{listing}/availability',  [MemberPropertyListingController::class, 'saveBlackouts'])->name('properties.listings.availability.blackouts');

    // Lease applications for a property's listing(s) — view (parity with the admin
    // application view), message the applicant, and approve/reject.
    Route::get('/properties/{property}/applications',                       [MemberLeaseApplicationController::class, 'index'])->name('properties.applications.index');
    Route::get('/properties/{property}/applications/{application}',         [MemberLeaseApplicationController::class, 'show'])->name('properties.applications.show');
    Route::post('/properties/{property}/applications/{application}/message', [MemberLeaseApplicationController::class, 'message'])->name('properties.applications.message');
    Route::post('/properties/{property}/applications/{application}/approve', [MemberLeaseApplicationController::class, 'approve'])->name('properties.applications.approve');
    Route::post('/properties/{property}/applications/{application}/reject',  [MemberLeaseApplicationController::class, 'reject'])->name('properties.applications.reject');

    // Team tab: managers (grant/revoke). The check-in log + all other tabs are
    // served by the details hub (PropertyDetailController::edit).
    Route::post('/properties/{property}/managers',            [MemberPropertyManagerController::class, 'store'])->name('properties.managers.store');
    Route::delete('/properties/{property}/managers/{manager}', [MemberPropertyManagerController::class, 'destroy'])->name('properties.managers.destroy');

    // Photos tab. The /temp routes (FilePond instant-upload + revert) are declared
    // before the {photo} routes so DELETE .../photos/temp isn't captured as {photo}.
    Route::post('/properties/{property}/photos/temp',               [MemberPropertyPhotoController::class, 'tempStore'])->name('properties.photos.temp.store')->middleware('throttle:60,1');
    Route::delete('/properties/{property}/photos/temp',             [MemberPropertyPhotoController::class, 'tempRevert'])->name('properties.photos.temp.revert')->middleware('throttle:60,1');
    Route::post('/properties/{property}/photos',                     [MemberPropertyPhotoController::class, 'store'])->name('properties.photos.store')->middleware('throttle:30,1');
    Route::put('/properties/{property}/photos/{photo}',             [MemberPropertyPhotoController::class, 'update'])->name('properties.photos.update');
    Route::post('/properties/{property}/photos/{photo}/primary',    [MemberPropertyPhotoController::class, 'setPrimary'])->name('properties.photos.primary');
    Route::post('/properties/{property}/photos/{photo}/move',       [MemberPropertyPhotoController::class, 'move'])->name('properties.photos.move');
    Route::delete('/properties/{property}/photos/{photo}',          [MemberPropertyPhotoController::class, 'destroy'])->name('properties.photos.destroy');

    // Map tab — image-based maps with percent-coordinate markers.
    // Temp routes are declared before the {mapImage} routes so DELETE .../map-images/temp
    // isn't captured as {mapImage} (mirrors the photos temp routes).
    Route::post('/properties/{property}/map-images/temp',           [MemberPropertyMapController::class, 'tempStore'])->name('properties.map.temp.store')->middleware('throttle:60,1');
    Route::delete('/properties/{property}/map-images/temp',         [MemberPropertyMapController::class, 'tempRevert'])->name('properties.map.temp.revert')->middleware('throttle:60,1');
    Route::get('/properties/{property}/map-images/{documentId}',    [MemberPropertyMapController::class, 'serveImage'])->name('properties.map.serve');
    Route::get('/properties/{property}/map-images/{mapImage}/download', [MemberPropertyMapController::class, 'downloadImage'])->name('properties.map.download');
    Route::post('/properties/{property}/map-images',                [MemberPropertyMapController::class, 'storeImage'])->name('properties.map.store')->middleware('throttle:30,1');
    Route::put('/properties/{property}/map-images/{mapImage}',      [MemberPropertyMapController::class, 'updateImage'])->name('properties.map.update');
    Route::post('/properties/{property}/map-images/{mapImage}/boundary', [MemberPropertyMapController::class, 'setBoundary'])->name('properties.map.boundary');
    Route::post('/properties/{property}/map-images/{mapImage}/restore', [MemberPropertyMapController::class, 'restoreImage'])->name('properties.map.restore');
    Route::delete('/properties/{property}/map-images/{mapImage}',   [MemberPropertyMapController::class, 'destroyImage'])->name('properties.map.destroy');
    Route::post('/properties/{property}/map-images/{mapImage}/markers', [MemberPropertyMapController::class, 'addMarker'])->name('properties.map.markers.store');
    Route::put('/properties/{property}/markers/{marker}',           [MemberPropertyMapController::class, 'updateMarker'])->name('properties.map.markers.update');
    Route::post('/properties/{property}/markers/{marker}/move',     [MemberPropertyMapController::class, 'moveMarker'])->name('properties.map.markers.move');
    Route::delete('/properties/{property}/markers/{marker}',        [MemberPropertyMapController::class, 'destroyMarker'])->name('properties.map.markers.destroy');

    // Contacts tab — manager field contacts + emergency/local contacts.
    Route::post('/properties/{property}/manager-contacts',          [MemberPropertyContactController::class, 'addManager'])->name('properties.contacts.managers.store');
    Route::delete('/properties/{property}/manager-contacts/{manager}', [MemberPropertyContactController::class, 'removeManager'])->name('properties.contacts.managers.destroy');
    Route::post('/properties/{property}/contacts',                  [MemberPropertyContactController::class, 'store'])->name('properties.contacts.store');
    Route::put('/properties/{property}/contacts/{contact}',         [MemberPropertyContactController::class, 'update'])->name('properties.contacts.update');
    Route::delete('/properties/{property}/contacts/{contact}',      [MemberPropertyContactController::class, 'destroy'])->name('properties.contacts.destroy');

    Route::post('/security/password',                [SecurityController::class, 'changePassword'])->name('security.password')->middleware('throttle:5,1');
    Route::post('/security/mfa/totp/enroll',         [SecurityController::class, 'enrollTotp'])->name('security.mfa.totp.enroll')->middleware('throttle:10,1');
    Route::post('/security/mfa/totp/confirm',        [SecurityController::class, 'confirmTotp'])->name('security.mfa.totp.confirm')->middleware('throttle:10,1');
    Route::post('/security/mfa/{method}/enable',     [SecurityController::class, 'enableMfa'])->name('security.mfa.enable');
    Route::post('/security/mfa/{method}/disable',    [SecurityController::class, 'disableMfa'])->name('security.mfa.disable');
    Route::post('/security/profile-visibility',      [SecurityController::class, 'setProfileVisibility'])->name('security.profile.visibility');
    Route::get('/security/username-check/{username}', [SecurityController::class, 'checkUsername'])->name('security.username.check')->middleware('throttle:30,1');
});

// SEC-043: the auth bootstrap (login, register, email verification, MFA,
// password reset, logout) runs before a per-user RLS context exists, so it
// connects as the trusted ah_system (BYPASSRLS) role.
Route::middleware('db.system')->group(function () {
    require __DIR__ . '/auth.php';
});
