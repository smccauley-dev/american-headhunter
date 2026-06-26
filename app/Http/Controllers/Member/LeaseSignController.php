<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Models\Lease\Lease;
use App\Services\Billing\SecurityDepositService;
use App\Services\Lease\EsignatureService;
use App\Services\Property\PropertyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LeaseSignController extends Controller
{
    public function show(string $lease, EsignatureService $esigService, PropertyService $propertyService, SecurityDepositService $depositService): Response|RedirectResponse
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
            'deposit'        => $this->buildDepositProps($leaseRecord, $depositService),
        ]);
    }

    public function sign(Request $request, string $lease, EsignatureService $esigService, SecurityDepositService $depositService): \Illuminate\Http\RedirectResponse
    {
        $userId = session('auth.user_id');

        $leaseRecord = Lease::where('id', $lease)
            ->where('lessee_user_id', $userId)
            ->whereNull('deleted_at')
            ->firstOrFail();

        abort_unless($leaseRecord->status === 'pending_signatures', 410);

        // Pay-then-sign gate: the lessee's signature is the one that activates the
        // lease, so a refundable deposit (when the listing requires one) must be held
        // before they can sign. This is the real enforcement — the UI mirror is
        // advisory. Only the lessee reaches this controller (lessee-scoped query),
        // so the landowner's counter-signature is never gated on a deposit.
        if (! $this->depositSatisfied($leaseRecord, $depositService)) {
            return redirect()->route('member.leases.sign', $lease)
                ->with('error', 'Please pay your refundable security deposit before signing.');
        }

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

    public function downloadEsignDocument(string $lease, string $document, EsignatureService $esigService): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        return $esigService->downloadEsignatureDocument($lease, $document, session('auth.user_id'));
    }

    /**
     * Whether the lessee may sign: true when no deposit is due for the lease, or
     * when one is due and already held. Read-only — derives the amount from the
     * listing and checks for a held row.
     */
    private function depositSatisfied(Lease $leaseRecord, SecurityDepositService $depositService): bool
    {
        if (rescue(fn () => $depositService->amountDueCents($leaseRecord), 0) <= 0) {
            return true;
        }

        $existing = rescue(fn () => $depositService->forLease($leaseRecord->id), null);

        return $existing !== null && $existing->status === 'held';
    }

    /**
     * Deposit props for the signing page, or null when no deposit is due. `held`
     * drives whether the signature form is unlocked; `pay_url` starts Checkout and
     * returns the lessee to this same signing step (return=sign).
     *
     * @return array{amount: string, held: bool, pay_url: string}|null
     */
    private function buildDepositProps(Lease $leaseRecord, SecurityDepositService $depositService): ?array
    {
        $amountDueCents = rescue(fn () => $depositService->amountDueCents($leaseRecord), 0) ?: 0;
        $existing       = rescue(fn () => $depositService->forLease($leaseRecord->id), null);

        if ($amountDueCents <= 0 && ! $existing) {
            return null;
        }

        $displayCents = $existing ? (int) $existing->amount_cents : $amountDueCents;

        return [
            'amount'  => number_format($displayCents / 100, 2),
            'held'    => $existing !== null && $existing->status === 'held',
            'pay_url' => route('member.leases.deposit', $leaseRecord->id),
        ];
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
