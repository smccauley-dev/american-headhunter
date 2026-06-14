<?php

namespace App\Http\Controllers\Member;

use App\Enums\LeaseDocumentTag;
use App\Http\Controllers\Controller;
use App\Models\Identity\User;
use App\Models\Lease\Lease;
use App\Services\Documents\DocumentService;
use App\Services\Lease\CheckInService;
use App\Services\Lease\EsignatureService;
use App\Services\Lease\LeaseDocumentService;
use App\Services\Lease\LeaseService;
use App\Services\Property\PropertyMapService;
use App\Services\Property\PropertyService;
use Inertia\Inertia;
use Inertia\Response;

class MemberController extends Controller
{
    public function dashboard(LeaseService $leaseService, CheckInService $checkInService): Response
    {
        $userId  = session('auth.user_id');
        $profile = User::find($userId)?->profile;
        $name    = $profile?->first_name ?: ($profile?->display_name ?: 'Member');

        $open = $checkInService->getOpenForUser($userId);

        return Inertia::render('Member/Dashboard', [
            'name'   => $name,
            'leases' => $leaseService->getLeaseSummariesForLessee($userId),
            'open_check_in' => $open ? [
                'lease_id'      => $open->lease_id,
                'checked_in_at' => $open->checked_in_at?->toIso8601String(),
            ] : null,
        ]);
    }

    public function show(string $lease, PropertyService $propertyService, EsignatureService $esigService, LeaseDocumentService $leaseDocumentService, CheckInService $checkInService, DocumentService $documentService, PropertyMapService $mapService): Response
    {
        $userId = session('auth.user_id');

        // Both lessees (hunters) and lessors (landowners) may view their lease
        $leaseRecord = Lease::where('id', $lease)
            ->where(function ($q) use ($userId) {
                $q->where('lessee_user_id', $userId)
                  ->orWhere('lessor_user_id', $userId);
            })
            ->whereNull('deleted_at')
            ->firstOrFail();

        $isLessor = $leaseRecord->lessor_user_id === $userId;

        $property = rescue(fn () => $propertyService->find($leaseRecord->property_id), null);

        // Contact directory — landowner + managers (derived) plus local law
        // enforcement, game warden, emergency and custom contacts. Available to
        // either party on any lease status.
        $contacts = rescue(fn () => $propertyService->getContactDirectory($leaseRecord->property_id), null);

        $accessInfo = null;
        if ($leaseRecord->status === 'active') {
            try {
                $result = $propertyService->getAccessInfo(
                    $leaseRecord->property_id,
                    $userId,
                    config('encryption_keys.property', ''),
                );
                $accessInfo = $result ?: null;
            } catch (\Throwable) {
                // Not configured or no access info stored
            }
        }

        $esigRequest    = $esigService->getRequestForLease($lease);
        $signers        = [];
        $mySignerStatus = null;

        if ($esigRequest) {
            $signers = $esigRequest->signers()->orderBy('order_num')->get()->map(fn ($s) => [
                'name'      => $s->name,
                'role'      => $s->order_num === 1 ? 'Lessor' : 'Lessee',
                'status'    => $s->status,
                'signed_at' => $s->signed_at?->toIso8601String(),
            ])->all();

            $mySignerStatus = $esigService->signerForUser($esigRequest->id, $userId)?->status;
        }

        $signUrl = ($leaseRecord->status === 'pending_signatures' && $mySignerStatus !== 'signed')
            ? route('member.leases.sign', $lease)
            : null;

        // Fully-executed lease PDF — available to either party on any status
        // once signing completed (Dropbox Sign stores the executed copy).
        $signedLeaseUrl = $esigService->signedLeaseDocumentId($lease) !== null
            ? route('member.leases.signed.download', $lease)
            : null;

        $leaseDocuments = $leaseDocumentService->getForLease($lease)->map(fn ($doc) => [
            'id'               => $doc->id,
            'tag'              => $doc->tag->value,
            'tag_label'        => $doc->tag->label(),
            'tag_badge_style'  => $doc->tag->badgeStyle(),
            'original_filename' => $doc->original_filename,
            'size_bytes'       => $doc->size_bytes,
            'created_at'       => $doc->created_at?->format('M j, Y'),
            'download_url'     => route('member.leases.documents.download', [$lease, $doc->id]),
            'delete_url'       => route('member.leases.documents.destroy', [$lease, $doc->id]),
        ])->values()->all();

        // Check-in + gate QR + stand map — active leases only. The QR is per
        // property (one gate code reused across leases); it's created at lease
        // activation, but get-or-create here covers leases activated earlier.
        $checkIn  = null;
        $qr       = null;
        $standMap = null;
        if ($leaseRecord->status === 'active') {
            $open = $checkInService->getOpenForUserLease($lease, $userId);
            $checkIn = [
                'open'          => $open ? ['checked_in_at' => $open->checked_in_at?->toIso8601String()] : null,
                'check_in_url'  => route('member.checkin.store'),
                'check_out_url' => route('member.checkin.destroy'),
            ];

            $qrCode = rescue(fn () => $documentService->getOrCreateCheckInQrForProperty($leaseRecord->property_id), null);
            if ($qrCode) {
                $qr = ['png_url' => route('checkin.qr.png', $qrCode->token)];
            }

            // Stand map = the landowner's boundary map image with read-only
            // markers. Members only — markers are passed here, not exposed
            // publicly (SEC-024). Shown in a modal on the lease page.
            $overlay = rescue(fn () => $mapService->getBoundaryOverlay($leaseRecord->property_id), null);
            if ($overlay) {
                $standMap = [
                    'image_url' => route('property-maps.show', $overlay['document_id']),
                    'markers'   => $overlay['markers'],
                ];
            }
        }

        return Inertia::render('Member/Lease', [
            'lease' => [
                'id'          => $leaseRecord->id,
                'status'      => $leaseRecord->status,
                'start_date'  => $leaseRecord->start_date?->format('F j, Y'),
                'end_date'    => $leaseRecord->end_date?->format('F j, Y'),
                'total_price' => number_format((float) $leaseRecord->total_price, 2),
                'auto_renew'  => $leaseRecord->auto_renew,
            ],
            'check_in'     => $checkIn,
            'qr'           => $qr,
            'stand_map'    => $standMap,
            'email_qr_url' => ($isLessor && $leaseRecord->status === 'active') ? route('member.leases.email-qr', $lease) : null,
            'property'    => $property ? [
                'id'     => $property->id,
                'title'  => $property->title,
                'county' => $property->county,
                'state'  => $property->state_code,
                'acres'  => $property->huntable_acres ?? $property->total_acres,
                'rules'  => collect($property->rules ?? [])->map(fn ($r) => $r->rule_text)->values()->all(),
            ] : null,
            'access_info'    => $accessInfo,
            'contacts'       => $contacts,
            'signers'         => $signers,
            'sign_url'        => $signUrl,
            'signed_lease_url' => $signedLeaseUrl,
            'is_lessor'      => $isLessor,
            'documents'      => $leaseDocuments,
            'document_tags'  => LeaseDocumentTag::options(),
            'upload_url'     => route('member.leases.documents.upload', $lease),
        ]);
    }
}
