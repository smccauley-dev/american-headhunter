<?php

namespace App\Http\Controllers\Member;

use App\Database\ConnectionRole;
use App\Enums\LeaseDocumentTag;
use App\Http\Controllers\Controller;
use App\Models\Identity\User;
use App\Models\Lease\Lease;
use App\Services\Documents\DocumentService;
use App\Services\Incidents\DamageClaimService;
use App\Services\Incidents\DisputeService;
use App\Services\Incidents\IncidentService;
use App\Services\Lease\ApplicationMessageService;
use App\Services\Lease\CheckInService;
use App\Services\Lease\EsignatureService;
use App\Services\Billing\BookingDepositService;
use App\Services\Billing\LeasePaymentService;
use App\Services\Billing\PayoutService;
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

    public function show(string $lease, PropertyService $propertyService, EsignatureService $esigService, LeaseDocumentService $leaseDocumentService, CheckInService $checkInService, DocumentService $documentService, PropertyMapService $mapService, ApplicationMessageService $messageService, SecurityDepositService $depositService, BookingDepositService $bookingDepositService, LeasePaymentService $leasePaymentService, PayoutService $payoutService, DisputeService $disputeService, DamageClaimService $damageClaimService, IncidentService $incidentService): Response
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

                // A forfeiture against the hunter is a CLAIM until adjudicated: the
                // money is held and the Trust Score hit is provisional. While it's
                // 'pending' the hunter can contest it (with photo evidence) or, when
                // insurance is on file, settle it via an opt-out. Once a dispute is
                // filed the page shows "under review"; once resolved, the outcome.
                $forfeit = null;
                $dispute = null;
                if ($existingDeposit && $existingDeposit->forfeit_trust_status !== null) {
                    $latestDispute   = rescue(fn () => $disputeService->latestForDeposit($existingDeposit->id), null);
                    $isPending       = $existingDeposit->forfeit_trust_status === 'pending';
                    $faultIsHunters  = in_array($existingDeposit->forfeit_fault, [SecurityDepositService::FAULT_LESSEE, SecurityDepositService::FAULT_CONTESTED], true);
                    $hasOpenDispute  = $latestDispute && ! in_array($latestDispute->status, ['resolved'], true);

                    $forfeit = [
                        'trust_status'     => $existingDeposit->forfeit_trust_status,
                        'fault'            => $existingDeposit->forfeit_fault,
                        'amount'           => number_format((int) $existingDeposit->forfeited_amount_cents / 100, 2),
                        'reason'           => $existingDeposit->forfeit_reason,
                        'category'         => $existingDeposit->forfeit_category,
                        'contest_deadline' => $existingDeposit->forfeit_contest_deadline?->format('F j, Y'),
                        'has_insurance'    => $existingDeposit->hasInsuranceCoverage(),
                        'can_contest'      => $isPending && $faultIsHunters && ! $latestDispute,
                        'can_opt_out'      => $isPending && $faultIsHunters && ! $hasOpenDispute,
                    ];

                    if ($latestDispute) {
                        $dispute = [
                            'status'   => $latestDispute->status,
                            'filed_at' => $latestDispute->created_at?->format('F j, Y'),
                        ];
                    }
                }

                $deposit = [
                    'status'      => $existingDeposit?->status,
                    'amount'      => number_format($displayCents / 100, 2),
                    'refunded'    => $existingDeposit ? number_format((int) $existingDeposit->refunded_amount_cents / 100, 2) : null,
                    'forfeited'   => $existingDeposit ? number_format((int) $existingDeposit->forfeited_amount_cents / 100, 2) : null,
                    'can_pay'     => ! $existingDeposit && $amountDueCents > 0,
                    'pay_url'     => route('member.leases.deposit', $lease),
                    'forfeit'     => $forfeit,
                    'dispute'     => $dispute,
                    'contest_url' => route('member.leases.forfeiture.contest', $lease),
                    'opt_out_url' => route('member.leases.forfeiture.opt-out', $lease),
                ];
            }
        }

        // Both lessee money flows (booking deposit + lease balance) now collect via a
        // Stripe Connect destination charge to the landowner, so both are gated on the
        // landowner being able to take charges. Resolve that once.
        $landowner      = ! $isLessor ? rescue(fn () => $leaseRecord->getLessor(), null) : null;
        $chargesEnabled = $landowner ? rescue(fn () => $payoutService->onboardingState($landowner)['charges_enabled'], false) : false;

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
                    'status'                    => $existingBooking?->status,
                    'amount'                    => number_format($bookingCents / 100, 2),
                    'paid'                      => $paid,
                    'landowner_charges_enabled' => $chargesEnabled,
                    'can_pay'                   => ! $existingBooking && $bookingDueCents > 0 && $chargesEnabled,
                    'pay_url'                   => route('member.leases.booking-deposit', $lease),
                    'remaining_balance'         => number_format(max(0, $remaining), 2),
                ];
            }
        }

        // Lease balance (lessee-facing). Settled via a Stripe Connect destination
        // charge to the landowner; only payable once the landowner can take charges.
        // The lease_payments row is system-authored — written via the webhook /
        // db.system return, never on this read request.
        $leasePayment = null;
        if (! $isLessor) {
            $balanceCents   = rescue(fn () => $leasePaymentService->balanceDueCents($leaseRecord), 0) ?: 0;
            $history        = rescue(fn () => $leasePaymentService->forLease($lease), collect()) ?: collect();

            if ($balanceCents > 0 || $history->isNotEmpty()) {
                $quote = ($balanceCents > 0 && $landowner)
                    ? rescue(fn () => $leasePaymentService->quote($leaseRecord, $landowner), null)
                    : null;

                $leasePayment = [
                    'balance'                   => number_format($balanceCents / 100, 2),
                    'balance_due'               => $balanceCents > 0,
                    'landowner_charges_enabled' => $chargesEnabled,
                    'surcharge'                 => $quote ? number_format($quote['surcharge_cents'] / 100, 2) : null,
                    'total_charge'              => $quote ? number_format($quote['gross_cents'] / 100, 2) : null,
                    'can_pay'                   => $balanceCents > 0 && $chargesEnabled,
                    'pay_url'                   => route('member.leases.lease-payment', $lease),
                    'payments'                  => $history->map(fn ($p) => [
                        'amount'  => number_format((int) $p->gross_cents / 100, 2),
                        'status'  => $p->status,
                        'paid_at' => $p->paid_at?->format('F j, Y'),
                    ])->values()->all(),
                ];
            }
        }

        // Damage claims (lessor-facing). A landowner files an itemized claim for
        // property/equipment damage with photo evidence; staff review and may settle
        // it from the held deposit. The row is system-authored — written only via the
        // db.system file route, never on this read request.
        $damageClaims = null;
        if ($isLessor) {
            $claims = rescue(fn () => $damageClaimService->forLease($lease), collect()) ?: collect();
            $damageClaims = [
                'claims' => $claims->map(fn ($c) => [
                    'claim_type'  => $c->claim_type,
                    'status'      => $c->status,
                    'amount'      => number_format((int) $c->amount_claimed_cents / 100, 2),
                    'approved'    => $c->amount_approved_cents !== null ? number_format((int) $c->amount_approved_cents / 100, 2) : null,
                    'description' => $c->description,
                    'filed_at'    => $c->created_at?->format('F j, Y'),
                ])->values()->all(),
                'file_url' => route('member.leases.damage-claims.store', $lease),
            ];
        }

        // Safety incidents (both parties). Either party files an incident on the
        // lease/property with optional photo evidence; the safety team triages it.
        // System-authored — rows are written only via the db.system report route.
        $incidentRows = rescue(fn () => $incidentService->forLease($lease), collect()) ?: collect();
        $incidents = [
            'reports' => $incidentRows->map(function ($i) use ($userId, $lease) {
                // The reporter may fix their own report until the safety team resolves/closes it.
                $isReporter = $i->reporter_user_id === $userId;
                $canEdit    = $isReporter && in_array($i->status, ['open', 'investigating'], true);
                $photos     = $isReporter
                    ? collect($i->evidence_document_ids ?? [])->map(fn ($docId) => [
                        'id'  => $docId,
                        'url' => route('member.leases.incident-photo', ['lease' => $lease, 'incident' => $i->id, 'documentId' => $docId]),
                    ])->values()->all()
                    : [];

                $items = collect($i->incident_items ?? [])->map(fn ($item) => [
                    'type'              => $item['type'] ?? null,
                    'severity'          => $item['severity'] ?? null,
                    'occurred_at'       => isset($item['occurred_at']) ? \Illuminate\Support\Carbon::parse($item['occurred_at'])->format('M j, Y g:i A') : null,
                    'occurred_at_input' => isset($item['occurred_at']) ? \Illuminate\Support\Carbon::parse($item['occurred_at'])->format('Y-m-d\TH:i') : null,
                ])->values()->all();

                // Parties (incl. any minor's name) are only exposed to the reporter who filed them.
                $parties = $isReporter
                    ? collect($i->parties_involved ?? [])->map(fn ($p) => [
                        'full_name' => $p['full_name'] ?? '',
                        'is_minor'  => (bool) ($p['is_minor'] ?? false),
                    ])->values()->all()
                    : [];

                return [
                    'id'                      => $i->id,
                    'incident_number'         => $i->incident_number,
                    'incident_type'           => $i->incident_type,
                    'severity'                => $i->severity,
                    'items'                   => $items,
                    'parties'                 => $parties,
                    'status'                  => $i->status,
                    'occurred_at'             => $i->occurred_at?->format('F j, Y'),
                    'occurred_at_input'       => $i->occurred_at?->format('Y-m-d\TH:i'),
                    'location_description'    => $i->location_description,
                    'description'             => $i->description,
                    'injuries_reported'       => $i->injuries_reported,
                    'authorities_notified'    => $i->authorities_notified,
                    'authority_report_number' => $i->authority_report_number,
                    'reported_at'             => $i->created_at?->format('F j, Y'),
                    'photos'                  => $photos,
                    'can_edit'                => $canEdit,
                    'edit_url'                => $canEdit ? route('member.leases.incidents.update', ['lease' => $lease, 'incident' => $i->id]) : null,
                ];
            })->values()->all(),
            'report_url' => route('member.leases.incidents.store', $lease),
        ];

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
            'lease_payment'  => $leasePayment,
            'damage_claims'  => $damageClaims,
            'incidents'      => $incidents,
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

    /**
     * Start the hosted Checkout that settles a lease's outstanding balance as a
     * Stripe Connect destination charge to the landowner. Only the lessee pays. The
     * collected lease_payment row is authored by the webhook / success-return (the
     * table is system-authored).
     */
    public function payLeaseBalance(Request $request, string $lease, LeasePaymentService $leasePayments): \Symfony\Component\HttpFoundation\Response
    {
        $userId = session('auth.user_id');

        $leaseRecord = Lease::where('id', $lease)
            ->where('lessee_user_id', $userId)
            ->whereNull('deleted_at')
            ->firstOrFail();

        $cancelUrl  = route('member.leases.show', $lease) . '?payment=cancel';
        $successUrl = route('member.leases.lease-payment.return', $lease) . '?session_id={CHECKOUT_SESSION_ID}';

        $payer = User::findOrFail($userId);

        try {
            $session = $leasePayments->createCheckoutSession($leaseRecord, $payer, $successUrl, $cancelUrl);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['lease_payment' => $e->getMessage()]);
        }

        return Inertia::location($session->url);
    }

    /**
     * Stripe lease-payment success return. Reconciles the collected row from the
     * completed Checkout Session up front so the lessee sees it paid without waiting
     * on the webhook (which remains the backstop). Runs under `db.system` because
     * lease_payments is system-authored. recordCollectedFromCheckout is idempotent on
     * the captured PaymentIntent, so racing the webhook is harmless.
     */
    public function leasePaymentReturn(Request $request, string $lease, LeasePaymentService $leasePayments, StripeService $stripe): RedirectResponse
    {
        $sessionId = (string) $request->query('session_id', '');

        if ($sessionId !== '') {
            rescue(function () use ($stripe, $leasePayments, $sessionId, $lease) {
                $session = $stripe->retrieveCheckoutSession($sessionId)->toArray();

                if (($session['metadata']['lease_id'] ?? null) === $lease) {
                    $leasePayments->recordCollectedFromCheckout($session);
                }
            });
        }

        return redirect()->route('member.leases.show', ['lease' => $lease, 'payment' => 'paid']);
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
     * Contest a pending deposit forfeiture (lessee only). Stores the photo evidence
     * as unattached documents, then files the dispute. Runs under db.system because
     * lease_disputes is system-authored — ah_runtime cannot write it.
     */
    public function contestForfeiture(Request $request, string $lease, DisputeService $disputeService, DocumentService $documentService): RedirectResponse
    {
        $userId = session('auth.user_id');

        $leaseRecord = Lease::where('id', $lease)
            ->where('lessee_user_id', $userId)
            ->whereNull('deleted_at')
            ->firstOrFail();

        $request->validate([
            'description' => 'required|string|max:2000',
            'evidence'    => 'nullable|array|max:10',
            'evidence.*'  => 'file|image|max:10240',
        ]);

        $docIds = [];
        foreach ($request->file('evidence', []) as $file) {
            $docIds[] = $documentService->storeUploadedFile($file, $userId, 'photo', unattached: true)->id;
        }

        try {
            $disputeService->fileForfeitureContest(
                $leaseRecord,
                User::findOrFail($userId),
                $request->input('description'),
                $docIds,
            );
        } catch (\RuntimeException $e) {
            return back()->withErrors(['contest' => $e->getMessage()]);
        }

        return back()->with('success', 'Your contest has been filed. Our team will review the evidence.');
    }

    /**
     * Opt a pending forfeiture out of the dispute system because insurance covers the
     * loss (lessee only). The settlement is binary — keep (forfeiture stands) or
     * refund — with no Trust Score for either party. Requires insurance on file or
     * provided here. Runs under db.system (security_deposits is system-authored).
     */
    public function optOutForfeiture(Request $request, string $lease, SecurityDepositService $depositService): RedirectResponse
    {
        $userId = session('auth.user_id');

        $leaseRecord = Lease::where('id', $lease)
            ->where('lessee_user_id', $userId)
            ->whereNull('deleted_at')
            ->firstOrFail();

        $request->validate([
            'disposition'   => 'required|in:keep,refund',
            'insurer_name'  => 'nullable|string|max:120',
            'policy_number' => 'nullable|string|max:80',
        ]);

        $deposit = $depositService->forLease($lease);
        if (! $deposit) {
            return back()->withErrors(['opt_out' => 'No security deposit found for this lease.']);
        }

        // When the hunter supplies their own policy, record it as the covered party;
        // otherwise rely on insurance already on file (e.g. the landowner's).
        $insurance = [];
        if ($request->filled('insurer_name')) {
            $insurance = [
                'covered_party' => 'hunter',
                'insurer_name'  => $request->input('insurer_name'),
                'policy_number' => $request->input('policy_number'),
            ];
        }

        try {
            $depositService->optOutForfeitDecision(
                $deposit->id,
                $request->input('disposition'),
                $userId,
                'Opted out via member portal',
                $insurance,
            );
        } catch (\RuntimeException $e) {
            return back()->withErrors(['opt_out' => $e->getMessage()]);
        }

        return back()->with('success', 'Settled via insurance — no fault recorded against either party.');
    }

    /**
     * File a damage claim (lessor only) with photo evidence and optional insurance.
     * Runs under db.system because damage_claims is system-authored.
     */
    public function fileDamageClaim(Request $request, string $lease, DamageClaimService $claimService, DocumentService $documentService): RedirectResponse
    {
        $userId = session('auth.user_id');

        $leaseRecord = Lease::where('id', $lease)
            ->where('lessor_user_id', $userId)
            ->whereNull('deleted_at')
            ->firstOrFail();

        $request->validate([
            'claim_type'    => 'required|in:property_damage,equipment_damage,other',
            'amount'        => 'required|numeric|min:0.01|max:1000000',
            'description'   => 'required|string|max:2000',
            'evidence'      => 'nullable|array|max:10',
            'evidence.*'    => 'file|image|max:10240',
            'insurer_name'  => 'nullable|string|max:120',
            'policy_number' => 'nullable|string|max:80',
        ]);

        $docIds = [];
        foreach ($request->file('evidence', []) as $file) {
            $docIds[] = $documentService->storeUploadedFile($file, $userId, 'photo', unattached: true)->id;
        }

        $insurance = [];
        if ($request->filled('insurer_name')) {
            $insurance = [
                'covered_party' => 'landowner',
                'insurer_name'  => $request->input('insurer_name'),
                'policy_number' => $request->input('policy_number'),
            ];
        }

        $claimService->file(
            $leaseRecord,
            User::findOrFail($userId),
            $request->input('claim_type'),
            (int) round((float) $request->input('amount') * 100),
            $request->input('description'),
            $docIds,
            $insurance,
        );

        return back()->with('success', 'Damage claim filed. Our team will review it.');
    }

    /**
     * File a safety incident report (either party to the lease) with optional photo
     * evidence. Runs under db.system because incident_reports is system-authored.
     */
    public function reportIncident(Request $request, string $lease, IncidentService $incidentService, DocumentService $documentService): RedirectResponse
    {
        $userId = session('auth.user_id');

        $leaseRecord = Lease::where('id', $lease)
            ->where(fn ($q) => $q->where('lessee_user_id', $userId)->orWhere('lessor_user_id', $userId))
            ->whereNull('deleted_at')
            ->firstOrFail();

        $validated = $request->validate([
            'items'                   => 'required|array|min:1|max:10',
            'items.*.type'            => 'required|in:hunting_accident,trespassing,property_damage,wildlife_encounter,medical,fire,other',
            'items.*.severity'        => 'required|in:minor,moderate,serious,critical',
            'items.*.occurred_at'     => 'required|date|before_or_equal:now',
            'location_description'    => 'nullable|string|max:500',
            'description'             => 'required|string|max:2000',
            'injuries_reported'       => 'boolean',
            'authorities_notified'    => 'boolean',
            'authority_report_number' => 'nullable|string|max:100',
            'parties'                 => 'nullable|array|max:20',
            'parties.*.full_name'     => 'nullable|string|max:200',
            'parties.*.is_minor'      => 'boolean',
            'evidence'                => 'nullable|array|max:10',
            'evidence.*'              => 'file|image|max:10240',
        ]);

        $docIds = [];
        foreach ($request->file('evidence', []) as $file) {
            $docIds[] = $documentService->storeUploadedFile($file, $userId, 'photo', unattached: true)->id;
        }

        $incidentService->file(
            $leaseRecord,
            User::findOrFail($userId),
            $validated,
            $docIds,
        );

        return back()->with('success', 'Incident reported. Our safety team will review it.');
    }

    /**
     * Edit a safety incident the member filed themselves (e.g. correcting a mistake).
     * Only the reporter may edit, and only while the safety team has not yet resolved
     * or closed it. Every change is diff-audited in IncidentService; added photos are
     * appended — existing evidence can never be removed. Runs under db.system.
     */
    public function updateIncident(Request $request, string $lease, string $incident, IncidentService $incidentService, DocumentService $documentService): RedirectResponse
    {
        $userId = session('auth.user_id');

        $leaseRecord = Lease::where('id', $lease)
            ->where(fn ($q) => $q->where('lessee_user_id', $userId)->orWhere('lessor_user_id', $userId))
            ->whereNull('deleted_at')
            ->firstOrFail();

        // The reporter may edit only their own report on this lease, and only before triage closes it.
        $report = \App\Models\Incidents\IncidentReport::where('id', $incident)
            ->where('lease_id', $leaseRecord->id)
            ->where('reporter_user_id', $userId)
            ->firstOrFail();

        abort_unless(in_array($report->status, [IncidentService::STATUS_OPEN, IncidentService::STATUS_INVESTIGATING], true), 403, 'This incident can no longer be edited.');

        $validated = $request->validate([
            'items'                   => 'required|array|min:1|max:10',
            'items.*.type'            => 'required|in:hunting_accident,trespassing,property_damage,wildlife_encounter,medical,fire,other',
            'items.*.severity'        => 'required|in:minor,moderate,serious,critical',
            'items.*.occurred_at'     => 'required|date|before_or_equal:now',
            'location_description'    => 'nullable|string|max:500',
            'description'             => 'required|string|max:2000',
            'injuries_reported'       => 'boolean',
            'authorities_notified'    => 'boolean',
            'authority_report_number' => 'nullable|string|max:100',
            'parties'                 => 'nullable|array|max:20',
            'parties.*.full_name'     => 'nullable|string|max:200',
            'parties.*.is_minor'      => 'boolean',
            'evidence'                => 'nullable|array|max:10',
            'evidence.*'              => 'file|image|max:10240',
        ]);

        $addDocIds = [];
        foreach ($request->file('evidence', []) as $file) {
            $addDocIds[] = $documentService->storeUploadedFile($file, $userId, 'photo', unattached: true)->id;
        }

        $incidentService->updateDetails($report->id, $validated, $userId, $addDocIds);

        return back()->with('success', 'Incident updated. Your changes have been recorded.');
    }

    /**
     * Stream an incident's evidence photo to the reporter. The incident read runs under
     * ah_runtime, so RLS already scopes it to the reporter (and staff); we additionally
     * confirm the document actually belongs to this incident before serving it.
     */
    public function incidentPhoto(string $lease, string $incident, string $documentId)
    {
        $userId = session('auth.user_id');

        $report = \App\Models\Incidents\IncidentReport::where('id', $incident)
            ->where('lease_id', $lease)
            ->where('reporter_user_id', $userId)
            ->firstOrFail();

        abort_unless(in_array($documentId, $report->evidence_document_ids ?? [], true), 404);

        $doc  = \App\Models\Documents\Document::on('documents')->findOrFail($documentId);
        $disk = \Illuminate\Support\Facades\Storage::disk(config('filesystems.defaults.documents', 'local'));
        abort_unless($disk->exists($doc->storage_key), 404);

        return $disk->response(
            $doc->storage_key,
            $doc->original_filename,
            ['Content-Type' => $doc->mime_type ?? 'image/jpeg', 'Cache-Control' => 'private, max-age=3600'],
        );
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
