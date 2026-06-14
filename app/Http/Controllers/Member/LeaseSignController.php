<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Models\Lease\Lease;
use App\Services\Lease\EsignatureService;
use App\Services\Property\PropertyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LeaseSignController extends Controller
{
    public function show(string $lease, EsignatureService $esigService, PropertyService $propertyService): Response|RedirectResponse
    {
        $userId = session('auth.user_id');

        $leaseRecord = Lease::where('id', $lease)
            ->where('lessee_user_id', $userId)
            ->whereNull('deleted_at')
            ->firstOrFail();

        // Once the lease has moved past signing (already signed/active/cancelled),
        // there's nothing to sign — send the member to their lease page rather
        // than throwing a 410 error.
        if ($leaseRecord->status !== 'pending_signatures') {
            $message = $leaseRecord->status === 'active'
                ? 'This lease is fully signed and active.'
                : 'This lease is no longer awaiting signatures.';

            return redirect()->route('member.leases.show', $lease)->with('success', $message);
        }

        $esigRequest = $esigService->getRequestForLease($lease);
        abort_unless($esigRequest !== null, 404, 'No signing request found for this lease.');

        $signer = $esigService->signerForUser($esigRequest->id, $userId);
        abort_unless($signer !== null, 403, 'You are not listed as a signer on this lease.');

        $signerList = $esigRequest->signers()->orderBy('order_num')->get()->map(fn ($s) => [
            'name'      => $s->name,
            'role'      => $s->order_num === 1 ? 'Lessor' : 'Lessee',
            'status'    => $s->status,
            'signed_at' => $s->signed_at?->toIso8601String(),
        ]);

        return Inertia::render('Member/Sign', [
            'lease'          => $this->buildLeaseProps($leaseRecord, $propertyService),
            'request_id'     => $esigRequest->id,
            'signers'        => $signerList,
            'already_signed' => $signer->status === 'signed',
        ]);
    }

    public function sign(Request $request, string $lease, EsignatureService $esigService): \Illuminate\Http\RedirectResponse
    {
        $userId = session('auth.user_id');

        $leaseRecord = Lease::where('id', $lease)
            ->where('lessee_user_id', $userId)
            ->whereNull('deleted_at')
            ->firstOrFail();

        abort_unless($leaseRecord->status === 'pending_signatures', 410);

        $request->validate([
            'request_id' => ['required', 'string'],
            'full_name'  => ['required', 'string', 'max:200'],
            'agreed'     => ['required', 'accepted'],
        ]);

        $esigRequest = $esigService->getRequestForLease($lease);
        abort_unless($esigRequest !== null && $esigRequest->id === $request->request_id, 422);

        $signer = $esigService->signerForUser($esigRequest->id, $userId);
        abort_unless($signer !== null, 403);

        if ($signer->status === 'signed') {
            return redirect()->route('member.leases.sign', $lease)
                ->with('info', 'You have already signed this lease.');
        }

        $activated = $esigService->recordSignature(
            $esigRequest->id,
            $userId,
            $request->ip(),
            $request->userAgent(),
        );

        if ($activated) {
            return redirect()->route('member.dashboard')
                ->with('success', 'Your lease is now active! Welcome to American Headhunter.');
        }

        return redirect()->route('member.leases.sign', $lease)
            ->with('success', 'Signed successfully. Your lease will become active once the landowner countersigns.');
    }

    /** Download the fully-executed lease PDF (any lease status, both parties). */
    public function downloadSigned(string $lease, EsignatureService $esigService): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        return $esigService->downloadSignedLease($lease, session('auth.user_id'));
    }

    private function buildLeaseProps(Lease $leaseRecord, PropertyService $propertyService): array
    {
        $property = rescue(fn () => $propertyService->find($leaseRecord->property_id), null);

        return [
            'id'          => $leaseRecord->id,
            'status'      => $leaseRecord->status,
            'start_date'  => $leaseRecord->start_date?->format('F j, Y'),
            'end_date'    => $leaseRecord->end_date?->format('F j, Y'),
            'total_price' => number_format((float)$leaseRecord->total_price, 2),
            'property'    => $property ? [
                'title'  => $property->title,
                'county' => $property->county,
                'state'  => $property->state_code,
                'acres'  => $property->huntable_acres ?? $property->total_acres,
            ] : null,
        ];
    }
}
