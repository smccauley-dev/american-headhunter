<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Mail\CheckInQrMail;
use App\Models\Lease\Lease;
use App\Services\Documents\DocumentService;
use App\Services\Documents\QrImageService;
use App\Services\Identity\UserService;
use App\Services\Lease\CheckInService;
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

    /**
     * The member-portal check-in landing page. Lists every active lease the
     * member may check in against (as lessee or approved hunter), each with its
     * current in-the-field status. Distinct from scan(): that lands from a
     * physical gate QR for one property; this is the browsable portal entry at
     * GET /member/checkin.
     */
    public function index(
        Request $request,
        CheckInService $checkInService,
        PropertyService $propertyService,
        DocumentService $documentService,
    ): InertiaResponse {
        $userId = $request->session()->get('auth.user_id');

        $leases = $checkInService->eligibleLeasesForUser($userId);

        // Resolve property titles (cross-DB, DB 2) and the gate-QR PNG url once
        // per property — one QR is shared across a property's leases.
        $titles = [];
        $qrUrls = [];
        foreach ($leases->pluck('property_id')->unique() as $propertyId) {
            $property = rescue(fn () => $propertyService->find($propertyId), null);
            $titles[$propertyId] = $property?->title ?? 'Property';

            $qrCode = rescue(fn () => $documentService->getOrCreateCheckInQrForProperty($propertyId), null);
            $qrUrls[$propertyId] = $qrCode ? route('checkin.qr.png', $qrCode->token) : null;
        }

        $rows = $leases->map(fn (Lease $lease) => [
            'lease_id'       => $lease->id,
            'property_title' => $titles[$lease->property_id] ?? 'Property',
            'end_date'       => $lease->end_date?->format('F j, Y'),
            'checked_in_at'  => $checkInService->getOpenForUserLease($lease->id, $userId)?->checked_in_at?->toIso8601String(),
            'qr_url'         => $qrUrls[$lease->property_id] ?? null,
        ])->values()->all();

        return Inertia::render('Member/CheckInIndex', [
            'leases'        => $rows,
            'check_in_url'  => route('member.checkin.store'),
            'check_out_url' => route('member.checkin.destroy'),
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
