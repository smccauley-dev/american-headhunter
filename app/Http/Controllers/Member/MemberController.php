<?php

namespace App\Http\Controllers\Member;

use App\Enums\LeaseDocumentTag;
use App\Http\Controllers\Controller;
use App\Models\Lease\Lease;
use App\Services\Lease\EsignatureService;
use App\Services\Lease\LeaseDocumentService;
use App\Services\Property\PropertyService;
use Inertia\Inertia;
use Inertia\Response;

class MemberController extends Controller
{
    public function dashboard(PropertyService $propertyService): Response
    {
        $userId = session('auth.user_id');

        $leases = Lease::whereIn('status', ['active', 'pending_signatures'])
            ->where('lessee_user_id', $userId)
            ->whereNull('deleted_at')
            ->orderByDesc('start_date')
            ->get();

        $leaseData = $leases->map(function (Lease $lease) use ($propertyService) {
            $property = rescue(fn () => $propertyService->find($lease->property_id), null);
            $endDate  = $lease->end_date;

            return [
                'id'                => $lease->id,
                'status'            => $lease->status,
                'start_date'        => $lease->start_date?->format('M j, Y'),
                'end_date'          => $endDate?->format('M j, Y'),
                'total_price'       => number_format((float) $lease->total_price, 2),
                'days_until_expiry' => $endDate
                    ? ($endDate->isPast() ? 0 : (int) $endDate->diffInDays(now()))
                    : null,
                'property' => $property ? [
                    'id'     => $property->id,
                    'title'  => $property->title,
                    'county' => $property->county,
                    'state'  => $property->state_code,
                    'acres'  => $property->huntable_acres ?? $property->total_acres,
                ] : null,
            ];
        });

        return Inertia::render('Member/Dashboard', [
            'leases' => $leaseData,
        ]);
    }

    public function show(string $lease, PropertyService $propertyService, EsignatureService $esigService, LeaseDocumentService $leaseDocumentService): Response
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

        $accessInfo = null;
        if ($leaseRecord->status === 'active') {
            try {
                $result = $propertyService->getAccessInfo(
                    $leaseRecord->property_id,
                    config('encryption_keys.property', ''),
                    callerHasVerifiedLease: true,
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

        $leaseDocuments = $leaseDocumentService->getForLease($lease)->map(fn ($doc) => [
            'id'               => $doc->id,
            'tag'              => $doc->tag->value,
            'tag_label'        => $doc->tag->label(),
            'tag_badge_style'  => $doc->tag->badgeStyle(),
            'original_filename' => $doc->original_filename,
            'size_bytes'       => $doc->size_bytes,
            'created_at'       => $doc->created_at?->format('M j, Y'),
            'download_url'     => route('member.leases.documents.download', [$lease, $doc->id]),
        ])->values()->all();

        return Inertia::render('Member/Lease', [
            'lease' => [
                'id'          => $leaseRecord->id,
                'status'      => $leaseRecord->status,
                'start_date'  => $leaseRecord->start_date?->format('F j, Y'),
                'end_date'    => $leaseRecord->end_date?->format('F j, Y'),
                'total_price' => number_format((float) $leaseRecord->total_price, 2),
                'auto_renew'  => $leaseRecord->auto_renew,
            ],
            'property'    => $property ? [
                'id'     => $property->id,
                'title'  => $property->title,
                'county' => $property->county,
                'state'  => $property->state_code,
                'acres'  => $property->huntable_acres ?? $property->total_acres,
                'rules'  => collect($property->rules ?? [])->map(fn ($r) => $r->rule_text)->values()->all(),
            ] : null,
            'access_info'    => $accessInfo,
            'signers'        => $signers,
            'sign_url'       => $signUrl,
            'is_lessor'      => $isLessor,
            'documents'      => $leaseDocuments,
            'document_tags'  => LeaseDocumentTag::options(),
            'upload_url'     => route('member.leases.documents.upload', $lease),
        ]);
    }
}
