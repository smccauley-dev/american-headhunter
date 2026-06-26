<?php

namespace App\Http\Controllers\Member;

use App\Database\ConnectionRole;
use App\Enums\LeaseDocumentTag;
use App\Http\Controllers\Controller;
use App\Models\Identity\User;
use App\Models\Lease\Lease;
use App\Services\Documents\DocumentService;
use App\Services\Lease\ApplicationMessageService;
use App\Services\Lease\CheckInService;
use App\Services\Lease\EsignatureService;
use App\Services\Billing\BookingDepositService;
use App\Services\Billing\SecurityDepositService;
use App\Services\Billing\StripeService;
use App\Services\Lease\LeaseDocumentService;
use App\Services\Lease\LeaseService;
use App\Services\Property\PropertyMapService;
use App\Services\Property\PropertyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function show(string $lease, PropertyService $propertyService, EsignatureService $esigService, LeaseDocumentService $leaseDocumentService, CheckInService $checkInService, DocumentService $documentService, PropertyMapService $mapService, ApplicationMessageService $messageService, SecurityDepositService $depositService, BookingDepositService $bookingDepositService): Response
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

        // Communications — the application message thread (landowner ↔ applicant,
        // plus staff). Keyed on the originating application. Sender-name resolution
        // hits the identity DB, which default-denies other users' rows under
        // ah_runtime (SEC-047), so it runs under ah_system.
        $communications = null;
        if ($leaseRecord->application_id) {
            $messages    = $messageService->getForApplication($leaseRecord->application_id);
            $senderNames = $this->resolveSenderNames($messages->pluck('sender_user_id'));

            $communications = [
                'messages' => $messages->map(fn ($m) => [
                    'role'        => $m->sender_role,
                    'sender_name' => $senderNames[$m->sender_user_id] ?? ucfirst($m->sender_role),
                    'is_me'       => $m->sender_user_id === $userId,
                    'message'     => $m->message,
                    'sent_at'     => $m->created_at?->format('M j, Y g:i A'),
                ])->values()->all(),
                'message_url' => route('member.leases.messages.store', $lease),
            ];
        }

        // Security deposit (lessee-facing). Amount derives from the listing; a held
        // deposit shows as secured. The pay action goes through hosted Checkout —
        // the row itself is authored by the webhook, never on this runtime request.
        $deposit = null;
        if (! $isLessor) {
            $existingDeposit = rescue(fn () => $depositService->forLease($lease), null);
            $amountDueCents  = rescue(fn () => $depositService->amountDueCents($leaseRecord), 0) ?: 0;

            if ($existingDeposit || $amountDueCents > 0) {
                $displayCents = $existingDeposit ? (int) $existingDeposit->amount_cents : $amountDueCents;
                $deposit = [
                    'status'    => $existingDeposit?->status,
                    'amount'    => number_format($displayCents / 100, 2),
                    'refunded'  => $existingDeposit ? number_format((int) $existingDeposit->refunded_amount_cents / 100, 2) : null,
                    'forfeited' => $existingDeposit ? number_format((int) $existingDeposit->forfeited_amount_cents / 100, 2) : null,
                    'can_pay'   => ! $existingDeposit && $amountDueCents > 0,
                    'pay_url'   => route('member.leases.deposit', $lease),
                ];
            }
        }

        // Non-refundable booking deposit (lessee-facing). Credited toward the lease
        // total; the page shows the remaining balance (total − booking deposit paid).
        $bookingDeposit = null;
        if (! $isLessor) {
            $existingBooking = rescue(fn () => $bookingDepositService->forLease($lease), null);
            $bookingDueCents = rescue(fn () => $bookingDepositService->amountDueCents($leaseRecord), 0) ?: 0;

            if ($existingBooking || $bookingDueCents > 0) {
                $bookingCents = $existingBooking ? (int) $existingBooking->amount_cents : $bookingDueCents;
                $paid         = $existingBooking !== null && in_array($existingBooking->status, ['collected', 'disbursed'], true);
                $remaining    = (float) $leaseRecord->total_price - ($paid ? $bookingCents / 100 : 0);
                $bookingDeposit = [
                    'status'            => $existingBooking?->status,
                    'amount'            => number_format($bookingCents / 100, 2),
                    'paid'              => $paid,
                    'can_pay'           => ! $existingBooking && $bookingDueCents > 0,
                    'pay_url'           => route('member.leases.booking-deposit', $lease),
                    'remaining_balance' => number_format(max(0, $remaining), 2),
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
            'deposit'        => $deposit,
            'booking_deposit' => $bookingDeposit,
            'contacts'       => $contacts,
            'signers'         => $signers,
            'sign_url'        => $signUrl,
            'signed_lease_url' => $signedLeaseUrl,
            'is_lessor'      => $isLessor,
            'documents'      => $leaseDocuments,
            'document_tags'  => LeaseDocumentTag::options(),
            'upload_url'     => route('member.leases.documents.upload', $lease),
            'communications' => $communications,
        ]);
    }

    /**
     * Start the hosted Checkout that funds a lease's refundable security deposit.
     * Only the lessee pays. Redirects to Stripe; the held row is authored by the
     * webhook on payment success.
     */
    public function payDeposit(Request $request, string $lease, SecurityDepositService $depositService): \Symfony\Component\HttpFoundation\Response
    {
        $userId = session('auth.user_id');

        $leaseRecord = Lease::where('id', $lease)
            ->where('lessee_user_id', $userId)
            ->whereNull('deleted_at')
            ->firstOrFail();

        $existing = $depositService->forLease($lease);
        if ($existing && $existing->status === 'held') {
            return back()->withErrors(['deposit' => 'A deposit is already held for this lease.']);
        }
        if ($depositService->amountDueCents($leaseRecord) <= 0) {
            return back()->withErrors(['deposit' => 'No security deposit is due for this lease.']);
        }

        // When the lessee pays from the signing step, send them back there once the
        // deposit is held rather than to the lease page (return=sign). Default stays
        // the lease page for the standalone "pay deposit" action.
        $returnToSign = $request->input('return') === 'sign';
        $cancelUrl = $returnToSign
            ? route('member.leases.sign', $lease) . '?deposit=cancel'
            : route('member.leases.show', $lease) . '?deposit=cancel';
        $successUrl = route('member.leases.deposit.return', $lease) . '?session_id={CHECKOUT_SESSION_ID}'
            . ($returnToSign ? '&return=sign' : '');

        $payer   = User::findOrFail($userId);
        $session = $depositService->createCheckoutSession(
            $leaseRecord,
            $payer,
            // Return through deposit/return so the held row is reconciled from the
            // session immediately — the page then renders "Held" on first load
            // without waiting on the webhook. Stripe substitutes the real id for
            // {CHECKOUT_SESSION_ID}; leave the braces literal (do not url-encode).
            $successUrl,
            $cancelUrl,
        );

        return Inertia::location($session->url);
    }

    /**
     * Stripe deposit-payment success return. Reconciles the held deposit row from
     * the completed Checkout Session up front so the lessee sees "Held" without
     * waiting on the checkout.session.completed webhook (which remains the backstop).
     *
     * Runs under the `db.system` role (BYPASSRLS) because security_deposits is
     * system-authored — the runtime member role cannot write it. recordHeldFromCheckout
     * is idempotent on the captured PaymentIntent, so racing the webhook is harmless.
     */
    public function depositReturn(Request $request, string $lease, SecurityDepositService $depositService, StripeService $stripe): RedirectResponse
    {
        $sessionId = (string) $request->query('session_id', '');

        if ($sessionId !== '') {
            // Best-effort: a Stripe hiccup must not block the member's return to
            // their lease — the webhook still authors the row if this misses.
            rescue(function () use ($stripe, $depositService, $sessionId, $lease) {
                $session = $stripe->retrieveCheckoutSession($sessionId)->toArray();

                // Only reconcile when the session is this lease's deposit, so a
                // mismatched id can't author a row through the wrong lease URL.
                if (($session['metadata']['lease_id'] ?? null) === $lease) {
                    $depositService->recordHeldFromCheckout($session);
                }
            });
        }

        if ($request->query('return') === 'sign') {
            return redirect()->route('member.leases.sign', $lease)
                ->with('success', 'Deposit received. You can now sign your lease.');
        }

        return redirect()->route('member.leases.show', ['lease' => $lease, 'deposit' => 'paid']);
    }

    /**
     * Start the hosted Checkout that funds a lease's non-refundable booking deposit.
     * Only the lessee pays. Mirrors payDeposit; the collected row is authored by the
     * webhook on payment success (booking_deposits is system-authored).
     */
    public function payBookingDeposit(Request $request, string $lease, BookingDepositService $bookingDeposits): \Symfony\Component\HttpFoundation\Response
    {
        $userId = session('auth.user_id');

        $leaseRecord = Lease::where('id', $lease)
            ->where('lessee_user_id', $userId)
            ->whereNull('deleted_at')
            ->firstOrFail();

        $existing = $bookingDeposits->forLease($lease);
        if ($existing && in_array($existing->status, ['collected', 'disbursed'], true)) {
            return back()->withErrors(['deposit' => 'A booking deposit has already been paid for this lease.']);
        }
        if ($bookingDeposits->amountDueCents($leaseRecord) <= 0) {
            return back()->withErrors(['deposit' => 'No booking deposit is due for this lease.']);
        }

        $returnToSign = $request->input('return') === 'sign';
        $cancelUrl = $returnToSign
            ? route('member.leases.sign', $lease) . '?deposit=cancel'
            : route('member.leases.show', $lease) . '?deposit=cancel';
        $successUrl = route('member.leases.booking-deposit.return', $lease) . '?session_id={CHECKOUT_SESSION_ID}'
            . ($returnToSign ? '&return=sign' : '');

        $payer   = User::findOrFail($userId);
        $session = $bookingDeposits->createCheckoutSession($leaseRecord, $payer, $successUrl, $cancelUrl);

        return Inertia::location($session->url);
    }

    /**
     * Stripe booking-deposit success return. Reconciles the collected row from the
     * completed Checkout Session up front so the lessee sees it paid without waiting
     * on the webhook (which remains the backstop). Runs under `db.system` because
     * booking_deposits is system-authored. recordCollectedFromCheckout is idempotent
     * on the captured PaymentIntent, so racing the webhook is harmless.
     */
    public function bookingDepositReturn(Request $request, string $lease, BookingDepositService $bookingDeposits, StripeService $stripe): RedirectResponse
    {
        $sessionId = (string) $request->query('session_id', '');

        if ($sessionId !== '') {
            rescue(function () use ($stripe, $bookingDeposits, $sessionId, $lease) {
                $session = $stripe->retrieveCheckoutSession($sessionId)->toArray();

                if (($session['metadata']['lease_id'] ?? null) === $lease) {
                    $bookingDeposits->recordCollectedFromCheckout($session);
                }
            });
        }

        if ($request->query('return') === 'sign') {
            return redirect()->route('member.leases.sign', $lease)
                ->with('success', 'Booking deposit received. You can now sign your lease.');
        }

        return redirect()->route('member.leases.show', ['lease' => $lease, 'deposit' => 'paid']);
    }

    public function message(Request $request, string $lease, ApplicationMessageService $messageService): RedirectResponse
    {
        $userId = session('auth.user_id');

        $leaseRecord = Lease::where('id', $lease)
            ->where(function ($q) use ($userId) {
                $q->where('lessee_user_id', $userId)
                  ->orWhere('lessor_user_id', $userId);
            })
            ->whereNull('deleted_at')
            ->firstOrFail();

        abort_if($leaseRecord->application_id === null, 404, 'This lease has no application thread.');

        $data = $request->validate([
            'message' => 'required|string|max:2000',
        ]);

        $role = $leaseRecord->lessor_user_id === $userId ? 'landowner' : 'applicant';

        // ah_system so the recipient-notification email can resolve the other
        // party's identity row (SEC-047).
        ConnectionRole::asSystem(fn () => $messageService->send(
            $leaseRecord->application_id,
            $userId,
            $role,
            $data['message'],
        ));

        return back()->with('success', 'Message sent.');
    }

    /**
     * Map of user_id => display name from the identity DB. Must run within an
     * ah_system scope — the identity `users` RLS default-denies other users'
     * rows under ah_runtime (SEC-047). asSystem is nest-safe.
     *
     * @param  \Illuminate\Support\Collection<int, string>  $userIds
     * @return array<string, string>
     */
    private function resolveSenderNames($userIds): array
    {
        $ids = $userIds->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        $users = ConnectionRole::asSystem(
            fn () => User::on('identity')->with('profile')->whereIn('id', $ids)->get()
        );

        return $users
            ->mapWithKeys(fn (User $u) => [$u->id => $u->profile?->full_name ?: $u->email])
            ->all();
    }
}
