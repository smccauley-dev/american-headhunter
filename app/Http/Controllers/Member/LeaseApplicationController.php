<?php

namespace App\Http\Controllers\Member;

use App\Database\ConnectionRole;
use App\Enums\LeaseDocumentTag;
use App\Http\Controllers\Controller;
use App\Models\Documents\Document;
use App\Models\Identity\User;
use App\Models\Lease\Lease;
use App\Models\Lease\LeaseApplication;
use App\Models\Lease\LeaseApplicationHunter;
use App\Services\Billing\LeaseFinanceSummaryService;
use App\Services\Lease\ApplicationMessageService;
use App\Services\Lease\ApplicationService;
use App\Services\Lease\EsignatureService;
use App\Services\Lease\LeaseDocumentService;
use App\Services\Property\PropertyService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Landowner front-end lease applications (member portal) — the same information a
 * staff member sees on the admin ViewLeaseApplication page, scoped to one of the
 * landowner's own properties: the applications across that property's listing(s),
 * plus the ability to message the applicant and approve/reject.
 *
 * Authorization is service-layer (PropertyService::userCanManageProperty — the
 * properties table has no RLS policy) and every application is re-checked to
 * belong to the property. Cross-DB identity/document reads and the lease-creating
 * approve all run under ah_system: a landowner request is ah_runtime, where the
 * identity `users` RLS default-denies other users' rows (SEC-047) and the
 * `leases` table has no INSERT policy so lease creation would otherwise no-op /
 * throw (SEC-046).
 */
class LeaseApplicationController extends Controller
{
    public function __construct(
        private readonly PropertyService           $properties,
        private readonly ApplicationService        $applications,
        private readonly ApplicationMessageService $messages,
        private readonly EsignatureService         $esignatures,
        private readonly LeaseDocumentService      $leaseDocuments,
        private readonly LeaseFinanceSummaryService $leaseFinance,
    ) {}

    private const STATUS_LABELS = [
        'pending'      => 'Pending',
        'under_review' => 'Under Review',
        'approved'     => 'Approved',
        'rejected'     => 'Rejected',
        'withdrawn'    => 'Withdrawn',
        'expired'      => 'Expired',
    ];

    public function index(string $property): Response
    {
        $record = $this->authorizeManage($property);

        $applications = $this->applicationsForProperty($property);

        // Resolve applicant names in one cross-DB read under ah_system (SEC-047).
        $names = $this->resolveApplicantNames($applications->pluck('applicant_user_id'));

        $rows = $applications->map(fn (LeaseApplication $a) => [
            'id'             => $a->id,
            'ref'            => 'AH-' . strtoupper(substr($a->id, 0, 8)),
            'status'         => $a->status,
            'status_label'   => self::STATUS_LABELS[$a->status] ?? ucfirst($a->status),
            'applicant_name' => $names[$a->applicant_user_id] ?? '—',
            'type'           => $a->application_type === 'club' ? 'Club' : 'Individual',
            'listing_title'  => $a->property_title_snapshot ?: $record->title,
            'hunters'        => (int) $a->desired_hunters,
            'submitted_at'   => $a->created_at?->format('M j, Y'),
            'has_lease'      => $a->lease()->exists(),
        ])->values()->all();

        return Inertia::render('Member/Properties/Applications/Index', [
            'property' => $this->propertyHead($record),
            'applications' => $rows,
        ]);
    }

    public function show(string $property, string $application): Response
    {
        $record = $this->authorizeManage($property);
        $app    = $this->authorizeApplication($property, $application);

        return Inertia::render('Member/Properties/Applications/Show', array_merge(
            ['property' => $this->propertyHead($record)],
            $this->assembleDetail($record, $app),
        ));
    }

    public function message(Request $request, string $property, string $application): RedirectResponse
    {
        $this->authorizeManage($property);
        $app = $this->authorizeApplication($property, $application);

        $data = $request->validate([
            'message' => 'required|string|max:2000',
        ]);

        // ah_system so the applicant-notification email can resolve the
        // applicant's identity row (SEC-047).
        ConnectionRole::asSystem(fn () => $this->messages->send(
            $app->id,
            session('auth.user_id'),
            'landowner',
            $data['message'],
        ));

        return back()->with('success', 'Message sent to applicant.');
    }

    public function approve(Request $request, string $property, string $application): RedirectResponse
    {
        $this->authorizeManage($property);
        $app = $this->authorizeApplication($property, $application);

        if ($app->status !== 'pending') {
            return back()->with('error', 'Only a pending application can be approved.');
        }

        $data = $request->validate([
            'start_date'       => 'required|date',
            'end_date'         => 'required|date|after:start_date',
            'total_price'      => 'required|numeric|min:0',
            'sign_as_lessor'   => 'boolean',
            'notify_applicant' => 'boolean',
        ]);

        $reviewerId = session('auth.user_id');

        try {
            // Creating the lease INSERTs into the RLS-protected `leases` /
            // `lease_hunters` tables, which have no runtime write policy — run
            // the whole approval (and the applicant signing-link message) under
            // ah_system (SEC-046).
            $result = ConnectionRole::asSystem(function () use ($app, $reviewerId, $data, $request) {
                $res = $this->applications->approveAndCreateLease(
                    $app->id,
                    $reviewerId,
                    [
                        'start_date'  => $data['start_date'],
                        'end_date'    => $data['end_date'],
                        'total_price' => $data['total_price'],
                    ],
                    null,
                    ! empty($data['sign_as_lessor']),
                    $request->ip() ?? '',
                    $request->userAgent() ?? '',
                );

                if (! empty($data['notify_applicant']) && ! $res['activated']) {
                    $signingUrl = route('member.leases.sign', $res['lease']->id);
                    $this->messages->send(
                        $app->id,
                        $reviewerId,
                        'landowner',
                        "Your lease application has been approved! Please review and sign your lease agreement here: {$signingUrl}",
                    );
                }

                return $res;
            });
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Approval failed — the application was left unchanged.');
        }

        return back()->with('success', $result['activated']
            ? 'Application approved — lease is active (both parties signed).'
            : 'Application approved — lease created, awaiting lessee signature.');
    }

    public function reject(Request $request, string $property, string $application): RedirectResponse
    {
        $this->authorizeManage($property);
        $app = $this->authorizeApplication($property, $application);

        if ($app->status !== 'pending') {
            return back()->with('error', 'Only a pending application can be rejected.');
        }

        $data = $request->validate([
            'rejection_reason' => 'required|string|max:500',
            'notify_applicant' => 'boolean',
        ]);

        $reviewerId = session('auth.user_id');

        ConnectionRole::asSystem(function () use ($app, $reviewerId, $data) {
            $this->applications->reject($app->id, $reviewerId, $data['rejection_reason']);

            if (! empty($data['notify_applicant'])) {
                $this->messages->send(
                    $app->id,
                    $reviewerId,
                    'landowner',
                    "Your lease application has been rejected. Reason: {$data['rejection_reason']}",
                );
            }
        });

        return back()->with('success', 'Application rejected.');
    }

    // ── Detail assembly ────────────────────────────────────────────────────────

    /**
     * Everything the admin view shows, assembled for one application. The cross-DB
     * reads (applicant identity, lease, signers) run under ah_system because a
     * landowner request is ah_runtime where those rows default-deny (SEC-047).
     *
     * @return array<string, mixed>
     */
    private function assembleDetail(\App\Models\Property\Property $property, LeaseApplication $app): array
    {
        $hunters = $this->applications->getHuntersForApplication($app->id);
        $messages = $this->messages->getForApplication($app->id);
        $history  = \App\Models\Lease\LeaseApplicationReviewHistory::where('application_id', $app->id)
            ->orderBy('created_at')
            ->get();

        return ConnectionRole::asSystem(function () use ($property, $app, $hunters, $messages, $history) {
            $applicant = User::on('identity')->with('profile')->find($app->applicant_user_id);
            $senderNames = $this->resolveApplicantNames($messages->pluck('sender_user_id'));

            // Asking price from the listing, to pre-fill the approve modal's
            // total — flat price_total, else price_per_hunter × desired hunters.
            // Null (blank field) when the listing is archived or unpriced; the
            // landowner can always override the negotiated figure.
            $askingTotalPrice = $this->listingAskingPrice($app);

            $lease   = $app->lease()->first();
            $signers = null;
            $signingUrl = null;
            $documents = [];
            if ($lease) {
                $signingUrl  = route('member.leases.sign', $lease->id);
                $esigRequest = $this->esignatures->getRequestForLease($lease->id);
                $signers     = $esigRequest
                    ? $esigRequest->signers()->orderBy('order_num')->get()
                    : null;
                $documents   = $this->buildDocuments($lease);
            }

            return [
                'application' => [
                    'id'             => $app->id,
                    'ref'            => 'AH-' . strtoupper(substr($app->id, 0, 8)),
                    'status'         => $app->status,
                    'status_label'   => self::STATUS_LABELS[$app->status] ?? ucfirst($app->status),
                    'type'           => $app->application_type === 'club' ? 'Club' : 'Individual',
                    'proposed_start' => $app->proposed_start?->format('F j, Y'),
                    'proposed_end'   => $app->proposed_end?->format('F j, Y'),
                    'hunters'        => (int) $app->desired_hunters,
                    'submitted_at'   => $app->created_at?->format('F j, Y g:i A'),
                    'message'        => $app->message,
                    'admin_notes'    => $app->admin_notes,
                    'reviewed_at'    => $app->reviewed_at?->format('F j, Y g:i A'),
                    'rejection_reason' => $app->rejection_reason,
                ],
                'listing' => [
                    'ref'      => strtoupper(substr($app->listing_id, 0, 8)),
                    'title'    => $app->property_title_snapshot ?: $property->title,
                    'location' => $app->property_location_snapshot
                        ?: trim("{$property->county} County, {$property->state_code}"),
                ],
                'applicant' => [
                    'name'  => $applicant?->profile?->full_name ?: ($applicant?->email ?? '—'),
                    'email' => $applicant?->email ?? '',
                    'ref'   => strtoupper(substr($app->applicant_user_id, 0, 8)),
                ],
                'hunters' => $hunters->map(fn (LeaseApplicationHunter $h) => [
                    'name'     => trim("{$h->first_name} {$h->last_name}") ?: '—',
                    'type'     => $h->hunter_type === 'primary' ? 'Primary' : 'Hunter',
                    'is_minor' => (bool) $h->is_minor,
                    'email'    => $h->email,
                    'cell'     => $h->cell_phone,
                ])->values()->all(),
                'lease' => $lease ? [
                    'ref'        => 'AH-' . strtoupper(substr($lease->id, 0, 8)),
                    'status'     => $lease->status,
                    'start_date' => $lease->start_date?->format('M j, Y'),
                    'end_date'   => $lease->end_date?->format('M j, Y'),
                    'total_price' => $lease->total_price !== null ? (float) $lease->total_price : null,
                ] : null,
                'payment_summary' => $lease ? $this->leaseFinance->landownerSummary($lease) : null,
                'signers' => $signers?->map(fn ($s) => [
                    'name'      => $s->name,
                    'role'      => $s->user_id === $lease?->lessor_user_id ? 'Lessor (Landowner)' : 'Lessee (Hunter)',
                    'email'     => $s->email,
                    'status'    => $s->status,
                    'signed_at' => $s->signed_at?->format('M j, Y g:i A'),
                ])->values()->all() ?? [],
                'signing_url' => $signingUrl,
                'documents' => $documents,
                'messages' => $messages->map(fn ($m) => [
                    'role'        => $m->sender_role,
                    'sender_name' => $senderNames[$m->sender_user_id] ?? ucfirst($m->sender_role),
                    'message'     => $m->message,
                    'sent_at'     => $m->created_at?->format('M j, Y g:i A'),
                ])->values()->all(),
                'history' => $history->map(fn ($r) => [
                    'label'   => $r->label(),
                    'to'      => $r->to_status,
                    'reason'  => $r->reason,
                    'decided_at' => $r->created_at?->format('M j, Y g:i A'),
                ])->values()->all(),
                'defaults' => [
                    'start_date'  => ($app->proposed_start ?? $app->listing_season_start_snap)?->toDateString(),
                    'end_date'    => ($app->proposed_end ?? $app->listing_season_end_snap)?->toDateString(),
                    'total_price' => $askingTotalPrice,
                ],
            ];
        });
    }

    /**
     * The listing's asking price for this application, in dollars — a flat
     * `price_total`, otherwise `price_per_hunter` × the desired hunter count.
     * Returns null when the listing is archived/unresolvable, carries no usable
     * price, or per-hunter pricing has no hunter count to multiply. Used only to
     * pre-fill the approve modal; the landowner sets the final negotiated figure.
     */
    private function listingAskingPrice(LeaseApplication $app): ?float
    {
        $listing = $this->properties->findListing($app->listing_id);
        if (! $listing) {
            return null;
        }

        if ($listing->price_total !== null && (float) $listing->price_total > 0) {
            return (float) $listing->price_total;
        }

        $hunters = (int) $app->desired_hunters;
        if ($listing->price_per_hunter !== null && (float) $listing->price_per_hunter > 0 && $hunters > 0) {
            return (float) $listing->price_per_hunter * $hunters;
        }

        return null;
    }

    /**
     * The lease's documents — the e-signature template (MLA) and fully-executed
     * copy from the signing request, plus any general lease_documents attachments.
     * Mirrors the admin ViewLeaseApplication "Lease Documents" section. Download
     * URLs are member, party-authorized routes (landowner = lessor is a party).
     * Soft-deleted (admin recovery) documents are intentionally not surfaced here.
     *
     * Must be called within the ah_system scope (the document reads live in DB 11).
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildDocuments(Lease $lease): array
    {
        $documents = [];

        $esigRequest = $this->esignatures->getRequestForLease($lease->id);
        $esigDocIds  = array_filter([
            'mla'            => $esigRequest?->template_document_id,
            'fully_executed' => $esigRequest?->signed_document_id,
        ]);

        if (! empty($esigDocIds)) {
            $esigDocs = Document::on('documents')
                ->whereIn('id', array_values($esigDocIds))
                ->get(['id', 'original_filename', 'size_bytes', 'created_at'])
                ->keyBy('id');

            foreach ($esigDocIds as $tagKey => $docId) {
                $doc = $esigDocs->get($docId);
                if (! $doc) {
                    continue;
                }

                $tag = LeaseDocumentTag::from($tagKey);
                $documents[] = [
                    'label'        => $tag->label(),
                    'badge'        => strtoupper(str_replace('_', ' ', $tagKey)),
                    'subtitle'     => $tagKey === 'mla'
                        ? 'Contract sent for e-signature'
                        : 'Fully executed — all parties have signed',
                    'filename'     => $doc->original_filename ?? 'document.pdf',
                    'size'         => $doc->size_bytes ? number_format($doc->size_bytes / 1024, 0) . ' KB' : '',
                    'date'         => $doc->created_at?->format('M j, Y') ?? '',
                    'download_url' => $tagKey === 'fully_executed'
                        ? route('member.leases.signed.download', $lease->id)
                        : route('member.leases.esign.download', [$lease->id, $docId]),
                ];
            }
        }

        foreach ($this->leaseDocuments->getForLease($lease->id) as $ld) {
            $documents[] = [
                'label'        => $ld->tag->label(),
                'badge'        => strtoupper(str_replace('_', ' ', $ld->tag->value)),
                'subtitle'     => $ld->notes ?? '',
                'filename'     => $ld->original_filename ?? 'document.pdf',
                'size'         => $ld->size_bytes ? number_format($ld->size_bytes / 1024, 0) . ' KB' : '',
                'date'         => $ld->created_at?->format('M j, Y') ?? '',
                'download_url' => route('member.leases.documents.download', [$lease->id, $ld->id]),
            ];
        }

        return $documents;
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────

    /** Applications across this property's listings (current + snapshotted), newest first. */
    private function applicationsForProperty(string $propertyId): Collection
    {
        $listingIds = $this->properties->getListingsForProperty($propertyId)->pluck('id')->all();

        return LeaseApplication::on('lease')
            ->where(function ($q) use ($listingIds, $propertyId) {
                $q->whereIn('listing_id', $listingIds)
                  ->orWhere('property_id_snapshot', $propertyId);
            })
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Map of user_id => display name, resolved from the identity DB. Must be called
     * within an ah_system scope — the identity `users` RLS default-denies other
     * users' rows under ah_runtime (SEC-047).
     *
     * @param  \Illuminate\Support\Collection<int, string>  $userIds
     * @return array<string, string>
     */
    private function resolveApplicantNames($userIds): array
    {
        $ids = $userIds->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        // Nest-safe: asSystem captures and restores the prior credentials, so this
        // is correct whether or not we are already inside an ah_system scope.
        $users = ConnectionRole::asSystem(
            fn () => User::on('identity')->with('profile')->whereIn('id', $ids)->get()
        );

        return $users
            ->mapWithKeys(fn (User $u) => [$u->id => $u->profile?->full_name ?: $u->email])
            ->all();
    }

    private function propertyHead(\App\Models\Property\Property $record): array
    {
        return [
            'id'          => $record->id,
            'title'       => $record->title,
            'status'      => $record->status,
            'state_code'  => $record->state_code,
            'county'      => $record->county,
            'total_acres' => $record->total_acres !== null ? (float) $record->total_acres : null,
        ];
    }

    /** Resolve a property the current user owns or actively manages, or 404. */
    private function authorizeManage(string $propertyId): \App\Models\Property\Property
    {
        $userId = session('auth.user_id');
        abort_unless($this->properties->userCanManageProperty($userId, $propertyId), 404);

        return $this->properties->find($propertyId) ?? abort(404);
    }

    /** The application, guarded to belong to this property (via snapshot or its listing). */
    private function authorizeApplication(string $propertyId, string $applicationId): LeaseApplication
    {
        $app = LeaseApplication::on('lease')
            ->whereNull('deleted_at')
            ->find($applicationId) ?? abort(404);

        $belongs = $app->property_id_snapshot === $propertyId
            || $this->properties->findListingForProperty($propertyId, $app->listing_id) !== null;

        abort_unless($belongs, 404);

        return $app;
    }
}
