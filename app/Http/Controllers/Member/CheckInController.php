<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Mail\CheckInQrMail;
use App\Models\Lease\Lease;
use App\Services\Documents\DocumentService;
use App\Services\Documents\QrImageService;
use App\Services\Identity\UserService;
use App\Services\Lease\CheckInService;
use App\Services\Lease\LeaseService;
use App\Services\Property\GeospatialService;
use App\Services\Property\PropertyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class CheckInController extends Controller
{
    /**
     * Public scan landing — the gate QR opens this URL. Works without login;
     * prompts login (and returns here afterwards) when not authenticated.
     */
    public function scan(
        string $token,
        Request $request,
        DocumentService $documentService,
        CheckInService $checkInService,
        PropertyService $propertyService,
    ): InertiaResponse|RedirectResponse {
        $qr = $documentService->resolveQrToken($token);
        abort_if($qr === null || $qr->code_type !== 'check_in', 404);

        if (! $request->session()->get('auth.user_id')) {
            // Stash this URL as the intended destination, then send to login.
            return redirect()->guest(route('auth.login'));
        }

        $userId     = $request->session()->get('auth.user_id');
        $propertyId = $qr->target_id;

        $lease    = $checkInService->activeLeaseForUserProperty($userId, $propertyId);
        $property = rescue(fn () => $propertyService->find($propertyId), null);
        $open     = $lease ? $checkInService->getOpenForUserLease($lease->id, $userId) : null;

        return Inertia::render('Member/CheckIn', [
            'property' => $property ? [
                'title'  => $property->title,
                'county' => $property->county,
                'state'  => $property->state_code,
            ] : null,
            'lease' => $lease ? [
                'id'       => $lease->id,
                'end_date' => $lease->end_date?->format('F j, Y'),
            ] : null,
            'open_check_in' => $open ? [
                'checked_in_at' => $open->checked_in_at?->toIso8601String(),
            ] : null,
            'check_in_url'  => route('member.checkin.store'),
            'check_out_url' => route('member.checkin.destroy'),
        ]);
    }

    /**
     * Render the property check-in QR as a PNG. Public: it only encodes the
     * (already random) scan token, and is embedded in member pages and emails.
     */
    public function png(string $token, DocumentService $documentService, QrImageService $qrImage): Response
    {
        $exists = \App\Models\Documents\QrCode::where('token', $token)
            ->where('code_type', 'check_in')
            ->whereNull('deleted_at')
            ->exists();
        abort_unless($exists, 404);

        $png = $qrImage->png(route('checkin.scan', $token), 360);

        return response($png, 200, [
            'Content-Type'  => 'image/png',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    public function store(Request $request, CheckInService $checkInService): RedirectResponse
    {
        $userId = $request->session()->get('auth.user_id');

        $data = $request->validate([
            'lease_id' => ['required', 'string'],
            'lat'      => ['nullable', 'numeric', 'between:-90,90'],
            'lng'      => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $result = $checkInService->checkIn(
            $userId,
            $data['lease_id'],
            isset($data['lat']) ? (float) $data['lat'] : null,
            isset($data['lng']) ? (float) $data['lng'] : null,
        );

        $message = $result['new'] ? 'Checked in. Stay safe out there.' : 'You are already checked in.';
        if ($result['within_boundary'] === false) {
            $message .= ' Heads up — your location reads as outside the mapped property boundary.';
        }

        return back()->with('success', $message);
    }

    public function destroy(Request $request, CheckInService $checkInService): RedirectResponse
    {
        $userId = $request->session()->get('auth.user_id');

        $data = $request->validate(['lease_id' => ['required', 'string']]);

        $checkInService->checkOut($userId, $data['lease_id']);

        return back()->with('success', 'Checked out. Welcome back.');
    }

    /**
     * Mapbox stand map for the lease's property. Member-only on-property GPS
     * (SEC-024): only active parties to the lease may see stand markers.
     */
    public function stands(
        string $lease,
        Request $request,
        LeaseService $leaseService,
        GeospatialService $geo,
        PropertyService $propertyService,
    ): InertiaResponse {
        $userId = $request->session()->get('auth.user_id');

        $leaseRecord = Lease::where('id', $lease)
            ->where(fn ($q) => $q
                ->where('lessee_user_id', $userId)
                ->orWhere('lessor_user_id', $userId))
            ->whereNull('deleted_at')
            ->firstOrFail();

        abort_unless(
            $leaseService->userHasActiveLeaseForProperty($userId, $leaseRecord->property_id),
            403,
        );

        $property = rescue(fn () => $propertyService->find($leaseRecord->property_id), null);
        $boundary = rescue(fn () => $geo->getPropertyBoundaryGeoJson($leaseRecord->property_id), null);
        $stands   = rescue(
            fn () => $geo->getPropertyStandsGeoJson($leaseRecord->property_id),
            ['type' => 'FeatureCollection', 'features' => []],
        );

        return Inertia::render('Member/Stands', [
            'lease_id' => $leaseRecord->id,
            'property' => $property ? [
                'title'  => $property->title,
                'county' => $property->county,
                'state'  => $property->state_code,
            ] : null,
            'boundary' => $boundary,
            'stands'   => $stands,
        ]);
    }

    /**
     * Email the property check-in QR to the lease's lessee. Available to the
     * landowner (lessor) on their own lease — a backstop for when the hunter
     * can't find the QR in the portal.
     */
    public function emailQr(
        string $lease,
        Request $request,
        DocumentService $documentService,
        UserService $userService,
        PropertyService $propertyService,
    ): RedirectResponse {
        $userId = $request->session()->get('auth.user_id');

        $leaseRecord = Lease::where('id', $lease)
            ->where('lessor_user_id', $userId)
            ->whereNull('deleted_at')
            ->firstOrFail();

        $qr = $documentService->getOrCreateCheckInQrForProperty($leaseRecord->property_id);

        $lessee = $userService->findById($leaseRecord->lessee_user_id);
        if (! $lessee?->email) {
            return back()->with('error', 'Could not find an email address for this hunter.');
        }

        $property = rescue(fn () => $propertyService->find($leaseRecord->property_id), null);

        Mail::to($lessee->email)->queue(new CheckInQrMail(
            recipientName: $lessee->profile?->first_name ?: 'there',
            propertyTitle: $property?->title ?? 'your leased property',
            scanUrl:       route('checkin.scan', $qr->token),
        ));

        return back()->with('success', 'Check-in QR emailed to the hunter.');
    }
}
