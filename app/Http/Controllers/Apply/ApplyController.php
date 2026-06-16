<?php

namespace App\Http\Controllers\Apply;

use App\Http\Controllers\Controller;
use App\Http\Requests\Apply\SubmitApplicationRequest;
use App\Models\Identity\HunterCredentials;
use App\Models\Identity\User;
use App\Models\Identity\UserProfile;
use App\Models\Lease\Lease;
use App\Models\Lease\LeaseApplication;
use App\Services\Identity\GuestHunterService;
use App\Services\Lease\ApplicationMessageService;
use App\Services\Lease\ApplicationService;
use App\Services\Lease\EsignatureService;
use App\Services\Platform\LegalService;
use App\Services\Property\PropertyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

class ApplyController extends Controller
{
    public function __construct(
        private readonly PropertyService           $propertyService,
        private readonly ApplicationService        $applicationService,
        private readonly ApplicationMessageService $messageService,
        private readonly GuestHunterService        $guestHunterService,
        private readonly LegalService              $legalService,
        private readonly EsignatureService         $esignatureService,
    ) {}

    public function show(string $listingId, Request $request): Response|RedirectResponse
    {
        $listing = $this->propertyService->findListing($listingId);

        if (! $listing || $listing->status !== 'active') {
            abort(404);
        }

        $userId = $request->session()->get('auth.user_id');

        $existing = LeaseApplication::where('listing_id', $listingId)
            ->where('applicant_user_id', $userId)
            ->whereNotIn('status', ['withdrawn', 'rejected'])
            ->whereNull('deleted_at')
            ->first();

        if ($existing) {
            return redirect()->route('apply.status', $existing->id)
                ->with('success', 'You already have an application for this listing.');
        }

        $primaryHunter   = $this->buildPrimaryHunterData($userId);
        $savedGuests     = $this->guestHunterService
            ->getForUser($userId)
            ->map(fn ($g) => $this->guestHunterService->serializeForForm($g))
            ->values();
        $certDoc = $this->legalService->getActiveCertification();

        // Day-hunt applicants pick their own dates and need to see what is taken;
        // fixed-term (annual/seasonal) leases use the whole season, so the lookup
        // is wasted there.
        $unavailableRanges = $listing->listing_type === 'day_hunt'
            ? $this->propertyService->getUnavailableRanges($listing->id)
            : [];

        return inertia('Apply/Index', [
            'listing'           => $this->serializeListing($listing),
            'property'          => $this->serializeProperty($listing->property),
            'unavailableRanges' => $unavailableRanges,
            'primaryHunter'     => $primaryHunter,
            'savedGuests'       => $savedGuests,
            'certificationDoc' => $certDoc ? [
                'key'     => $certDoc->document_key,
                'version' => $certDoc->version,
                'title'   => $certDoc->title,
                'content' => $certDoc->content,
            ] : null,
        ]);
    }

    public function submit(string $listingId, SubmitApplicationRequest $request): RedirectResponse
    {
        $listing = $this->propertyService->findListing($listingId);

        if (! $listing || $listing->status !== 'active') {
            abort(404);
        }

        $userId       = $request->session()->get('auth.user_id');
        $huntersInput = $request->input('hunters', []);

        // Extract UploadedFile objects from the request — HTTP concern stays in the controller
        $uploadedFiles = [];
        foreach (array_keys($huntersInput) as $i) {
            foreach (['dl_photo', 'dl_photo_back', 'hunting_license_photo', 'hunting_license_photo_back'] as $field) {
                if ($request->hasFile("hunters.{$i}.{$field}")) {
                    $uploadedFiles[$i][$field] = $request->file("hunters.{$i}.{$field}");
                }
            }
        }

        $hunters = $this->applicationService->buildHuntersPayload($huntersInput, $uploadedFiles, $userId);

        // Best-effort guest hunter profiles — outside the lease transaction
        foreach ($hunters as $i => $hunter) {
            if ($hunter['hunter_type'] === 'guest' && ($request->input("hunters.{$i}.save_as_guest") === 'true' || $request->input("hunters.{$i}.save_as_guest") === true)) {
                $this->guestHunterService->createOrUpdate(
                    $hunter['guest_hunter_id'] ?? null,
                    $userId,
                    array_diff_key($hunter, array_flip(['hunter_type', 'user_id', 'guest_hunter_id', 'is_minor', 'dl_confirmed_current', 'hunting_license_confirmed_current', 'dl_document_id', 'hunting_license_document_id'])),
                );
            }
        }

        $certDoc     = $this->legalService->getActiveCertification();
        $application = $this->applicationService->submitAtomically(
            userId:     $userId,
            attributes: [
                'listing_id'        => $listingId,
                'applicant_user_id' => $userId,
                'application_type'  => $request->application_type,
                'message'           => $request->message,
                'proposed_start'    => $request->proposed_start,
                'proposed_end'      => $request->proposed_end,
            ],
            hunters:  $hunters,
            certDoc:  $certDoc,
            request:  $request,
        );

        return redirect()->route('apply.status', $application->id)
            ->with('success', 'Your application has been submitted. The landowner will be in touch.');
    }

    public function status(string $applicationId, Request $request): Response
    {
        $userId = $request->session()->get('auth.user_id');

        $application = LeaseApplication::where('id', $applicationId)
            ->where('applicant_user_id', $userId)
            ->whereNull('deleted_at')
            ->firstOrFail();

        // Mark all inbound messages as read when applicant views the page
        $this->messageService->markRead($applicationId, $userId);

        $listing  = $this->propertyService->findListing($application->listing_id);
        $hunters  = $this->applicationService->getHuntersForApplication($applicationId)
            ->map(fn ($h) => [
                'id'          => $h->id,
                'hunter_type' => $h->hunter_type,
                'first_name'  => $h->first_name,
                'last_name'   => $h->last_name,
                'is_minor'    => $h->is_minor,
            ]);

        $messages = $this->messageService->getForApplication($applicationId)
            ->map(fn ($m) => [
                'id'          => $m->id,
                'sender_role' => $m->sender_role,
                'message'     => $m->message,
                'is_mine'     => $m->sender_user_id === $userId,
                'created_at'  => $m->created_at?->toIso8601String(),
            ]);

        // When approved, surface a direct "Sign Lease" CTA if the applicant has a
        // lease still awaiting their signature — clearer than the link in the
        // approval message.
        $signUrl = null;
        $lease = Lease::on('lease')
            ->where('application_id', $applicationId)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->first();

        if ($lease && $lease->status === 'pending_signatures') {
            $esigRequest = $this->esignatureService->getRequestForLease($lease->id);
            $mySigner    = $esigRequest
                ? $this->esignatureService->signerForUser($esigRequest->id, $userId)
                : null;

            if ($mySigner?->status !== 'signed') {
                $signUrl = route('member.leases.sign', $lease->id);
            }
        }

        return inertia('Apply/Status', [
            'sign_url'    => $signUrl,
            'application' => [
                'id'               => $application->id,
                'status'           => $application->status,
                'application_type' => $application->application_type,
                'desired_hunters'  => $application->desired_hunters,
                'proposed_start'   => $application->proposed_start?->toDateString(),
                'proposed_end'     => $application->proposed_end?->toDateString(),
                'message'          => $application->message,
                'rejection_reason' => $application->rejection_reason,
                'submitted_at'     => $application->created_at?->toIso8601String(),
                'reviewed_at'      => $application->reviewed_at?->toIso8601String(),
            ],
            'hunters'   => $hunters,
            'messages'  => $messages,
            'listing'   => $listing ? $this->serializeListing($listing) : null,
            'property'  => $listing?->property ? $this->serializeProperty($listing->property) : null,
        ]);
    }

    public function sendMessage(string $applicationId, Request $request): RedirectResponse
    {
        $userId = $request->session()->get('auth.user_id');

        $request->validate([
            'message' => ['required', 'string', 'max:2000'],
        ]);

        LeaseApplication::where('id', $applicationId)
            ->where('applicant_user_id', $userId)
            ->whereNull('deleted_at')
            ->firstOrFail();

        $this->messageService->send($applicationId, $userId, 'applicant', $request->input('message'));

        return redirect()->route('apply.status', $applicationId)
            ->with('success', 'Your message has been sent.');
    }

    public function index(Request $request): Response
    {
        $userId = $request->session()->get('auth.user_id');

        $applications = LeaseApplication::where('applicant_user_id', $userId)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($a) => [
                'id'               => $a->id,
                'status'           => $a->status,
                'application_type' => $a->application_type,
                'desired_hunters'  => $a->desired_hunters,
                'proposed_start'   => $a->proposed_start?->toDateString(),
                'proposed_end'     => $a->proposed_end?->toDateString(),
                'submitted_at'     => $a->created_at?->toIso8601String(),
            ]);

        return inertia('Apply/MyApplications', [
            'applications' => $applications,
        ]);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function buildPrimaryHunterData(string $userId): array
    {
        $user        = User::on('identity')->find($userId);
        $profile     = UserProfile::on('identity')->where('user_id', $userId)->first();
        $credentials = HunterCredentials::on('identity')->where('user_id', $userId)->first();

        return [
            'hunter_type'                    => 'primary',
            'user_id'                        => $userId,
            'guest_hunter_id'                => null,
            'first_name'                     => $profile?->first_name ?? '',
            'last_name'                      => $profile?->last_name ?? '',
            'date_of_birth'                  => $profile?->date_of_birth?->format('Y-m-d') ?? '',
            'email'                          => $user?->email ?? '',
            'home_phone'                     => $credentials?->home_phone ?? '',
            'cell_phone'                     => $credentials?->cell_phone ?? ($user?->phone ?? ''),
            'address_line1'                  => $credentials?->address_line1 ?? '',
            'address_line2'                  => $credentials?->address_line2 ?? '',
            'city'                           => $credentials?->city ?? '',
            'state_code'                     => $credentials?->state_code ?? ($profile?->state_code ?? ''),
            'zip_code'                       => $credentials?->zip_code ?? ($profile?->zip_code ?? ''),
            'emergency_contact_name'         => $credentials?->emergency_contact_name ?? '',
            'emergency_contact_phone'        => $credentials?->emergency_contact_phone ?? '',
            'emergency_contact_relationship' => $credentials?->emergency_contact_relationship ?? '',
            'medical_conditions'             => $credentials?->medical_conditions ?? '',
            'dl_number'                      => $credentials?->dl_number ?? '',
            'dl_state'                       => $credentials?->dl_state ?? '',
            'dl_expiry'                      => $credentials?->dl_expiry?->format('Y-m-d') ?? '',
            'dl_document_id'                      => $credentials?->dl_document_id ?? null,
            'dl_document_id_back'                 => $credentials?->dl_document_id_back ?? null,
            'dl_confirmed_current'                => false,
            'hunting_license_number'              => $credentials?->hunting_license_number ?? '',
            'hunting_license_state'               => $credentials?->hunting_license_state ?? '',
            'hunting_license_expiry'              => $credentials?->hunting_license_expiry?->format('Y-m-d') ?? '',
            'hunting_license_document_id'         => $credentials?->hunting_license_document_id ?? null,
            'hunting_license_document_id_back'    => $credentials?->hunting_license_document_id_back ?? null,
            'hunting_license_confirmed_current'   => false,
        ];
    }

    private function serializeListing(object $listing): array
    {
        return [
            'id'               => $listing->id,
            'listing_type'     => $listing->listing_type,
            'status'           => $listing->status,
            // Date-only strings — the apply form binds these to <input type="date">
            // and compares them against YYYY-MM-DD, so a Carbon ISO timestamp would
            // break both.
            'season_start'     => $listing->season_start?->toDateString(),
            'season_end'       => $listing->season_end?->toDateString(),
            'min_hunters'      => $listing->min_hunters,
            'max_hunters'      => $listing->max_hunters,
            'price_per_hunter' => $listing->price_per_hunter,
            'price_total'      => $listing->price_total,
            'deposit_percent'  => $listing->deposit_percent,
            'deposit_amount'   => $listing->deposit_amount,
        ];
    }

    private function serializeProperty(object $property): array
    {
        return [
            'id'          => $property->id,
            'title'       => $property->title,
            'slug'        => $property->slug,
            'state_code'  => $property->state_code,
            'county'      => $property->county,
            'total_acres' => $property->total_acres,
        ];
    }
}
