<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\OutOfStateHuntException;
use App\Http\Controllers\Controller;
use App\Models\Identity\HunterCredentials;
use App\Models\Identity\User;
use App\Models\Identity\UserProfile;
use App\Models\Lease\Lease;
use App\Models\Lease\LeaseApplication;
use App\Services\Billing\BookingDepositService;
use App\Services\Lease\ApplicationService;
use App\Services\Platform\LegalService;
use App\Services\Property\PropertyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Mobile lease-application API. Runs as the Sanctum member (ah_runtime); every
 * query is scoped to the caller's own applications. Requires the hunter:apply
 * ability.
 *
 * Scope note: the mobile apply submits the primary hunter drawn from the
 * caller's saved HunterCredentials (the same pre-fill the web apply form uses),
 * so credential capture / document uploads happen on the web portal, not here.
 * Guest hunters and per-hunter document uploads are intentionally out of this
 * slice. The critical legal/safety gates the web FormRequest enforces
 * (license-issuing-state must match the property, current DL + license, term
 * rules) are re-checked here, since ApplicationService::submit only gates the
 * single-state entitlement.
 */
class ApplicationController extends Controller
{
    public function __construct(
        private readonly PropertyService $properties,
        private readonly ApplicationService $applications,
        private readonly LegalService $legal,
    ) {}

    /** The caller's applications, newest first. */
    public function index(Request $request): JsonResponse
    {
        $rows = LeaseApplication::where('applicant_user_id', $request->user()->id)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (LeaseApplication $a) => $this->summarize($a))
            ->all();

        return response()->json(['applications' => $rows]);
    }

    /** One of the caller's applications, with hunters, listing, and booking-fee state. */
    public function show(string $application, Request $request, BookingDepositService $bookingDeposits): JsonResponse
    {
        $app = $this->ownApplicationOrFail($application, $request->user()->id);

        $hunters = $this->applications->getHuntersForApplication($app->id)
            ->map(fn ($h) => [
                'id' => $h->id,
                'hunter_type' => $h->hunter_type,
                'first_name' => $h->first_name,
                'last_name' => $h->last_name,
                'is_minor' => $h->is_minor,
            ])->all();

        $listing = rescue(fn () => $this->properties->findListing($app->listing_id), null);
        $lease = Lease::on('lease')
            ->where('application_id', $app->id)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->first();

        return response()->json([
            'application' => array_merge($this->summarize($app), [
                'message' => $app->message,
                'rejection_reason' => $app->rejection_reason,
                'closed_reason' => $app->closed_reason,
                'reviewed_at' => $app->reviewed_at?->toIso8601String(),
            ]),
            'hunters' => $hunters,
            'listing' => $listing ? $this->serializeListing($listing) : null,
            'property' => $listing?->property ? $this->serializeProperty($listing->property) : null,
            'lease' => $lease ? ['id' => $lease->id, 'status' => $lease->status] : null,
            'booking_fee' => $this->bookingFee($app, $lease, $bookingDeposits),
        ]);
    }

    /** Submit an application for a listing as the caller (primary hunter from saved credentials). */
    public function apply(string $listing, Request $request): JsonResponse
    {
        $listingModel = $this->properties->findListing($listing);
        if (! $listingModel || $listingModel->status !== 'active') {
            abort(404);
        }

        $userId = $request->user()->id;

        $existing = LeaseApplication::where('listing_id', $listing)
            ->where('applicant_user_id', $userId)
            ->whereNotIn('status', ['withdrawn', 'rejected'])
            ->whereNull('deleted_at')
            ->first();
        if ($existing) {
            return response()->json([
                'message' => 'You already have an application for this listing.',
                'application_id' => $existing->id,
            ], 409);
        }

        $data = $request->validate([
            'application_type' => ['required', 'in:individual,club'],
            'message' => ['nullable', 'string', 'max:1000'],
            'proposed_start' => ['nullable', 'date'],
            'proposed_end' => ['nullable', 'date'],
            'certification_accepted' => ['required', 'accepted'],
        ]);

        $hunter = $this->primaryHunterFromCredentials($userId);
        $this->assertCredentialsComplete($hunter);
        $this->assertLicenceMatchesProperty($hunter, $listingModel);

        [$start, $end] = $this->resolveTerm($listingModel, $data['proposed_start'] ?? null, $data['proposed_end'] ?? null);

        $certDoc = $this->legal->getActiveCertification();

        try {
            $app = $this->applications->submitAtomically(
                userId: $userId,
                attributes: [
                    'listing_id' => $listing,
                    'applicant_user_id' => $userId,
                    'application_type' => $data['application_type'],
                    'message' => $data['message'] ?? null,
                    'proposed_start' => $start,
                    'proposed_end' => $end,
                ],
                hunters: [$hunter],
                certDoc: $certDoc,
                request: $request,
            );
        } catch (OutOfStateHuntException $e) {
            throw ValidationException::withMessages(['listing' => $e->getMessage()]);
        }

        return response()->json(['application' => $this->summarize($app->refresh())], 201);
    }

    /** Withdraw one of the caller's applications. Only while it is still live. */
    public function withdraw(string $application, Request $request): JsonResponse
    {
        $app = $this->ownApplicationOrFail($application, $request->user()->id);

        if (! in_array($app->status, ['pending', 'under_review', 'approved'], true)) {
            return response()->json(['message' => 'This application can no longer be withdrawn.'], 422);
        }

        $this->applications->withdraw($app->id);

        return response()->json(['application' => $this->summarize($app->refresh())]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────

    private function ownApplicationOrFail(string $applicationId, string $userId): LeaseApplication
    {
        return LeaseApplication::where('id', $applicationId)
            ->where('applicant_user_id', $userId)
            ->whereNull('deleted_at')
            ->firstOrFail();
    }

    private function summarize(LeaseApplication $a): array
    {
        return [
            'id' => $a->id,
            'status' => $a->status,
            'application_type' => $a->application_type,
            'desired_hunters' => $a->desired_hunters,
            'proposed_start' => $a->proposed_start?->toDateString(),
            'proposed_end' => $a->proposed_end?->toDateString(),
            'property_title' => $a->property_title_snapshot,
            'property_location' => $a->property_location_snapshot,
            'submitted_at' => $a->created_at?->toIso8601String(),
        ];
    }

    /** Compact booking-fee block, or null when no fee applies. Mirrors the web status page. */
    private function bookingFee(LeaseApplication $app, ?Lease $lease, BookingDepositService $bookingDeposits): ?array
    {
        $amountDueCents = rescue(fn () => $bookingDeposits->amountDueForApplication($app), 0) ?: 0;
        $deposit = rescue(fn () => $bookingDeposits->forApplication($app->id), null);

        if ($amountDueCents <= 0 && ! $deposit) {
            return null;
        }

        $displayCents = $deposit ? (int) $deposit->amount_cents : $amountDueCents;

        return [
            'amount' => number_format($displayCents / 100, 2),
            'status' => $deposit?->status,
            'window_open' => $app->bookingWindowOpen(),
            'deadline' => $app->booking_fee_deadline?->toIso8601String(),
            'can_pay' => $app->status === 'approved' && $app->bookingWindowOpen() && $deposit === null,
            'lease_status' => $lease?->status,
            'completion_deadline' => $lease?->completion_deadline?->toIso8601String(),
        ];
    }

    private function primaryHunterFromCredentials(string $userId): array
    {
        $user = User::on('identity')->find($userId);
        $profile = UserProfile::on('identity')->where('user_id', $userId)->first();
        $creds = HunterCredentials::on('identity')->where('user_id', $userId)->first();

        return [
            'hunter_type' => 'primary',
            'user_id' => $userId,
            'first_name' => $profile?->first_name,
            'last_name' => $profile?->last_name,
            'date_of_birth' => $profile?->date_of_birth?->format('Y-m-d'),
            'email' => $user?->email,
            'home_phone' => $creds?->home_phone,
            'cell_phone' => $creds?->cell_phone ?? $user?->phone,
            'address_line1' => $creds?->address_line1,
            'address_line2' => $creds?->address_line2,
            'city' => $creds?->city,
            'state_code' => $creds?->state_code ?? $profile?->state_code,
            'zip_code' => $creds?->zip_code ?? $profile?->zip_code,
            'emergency_contact_name' => $creds?->emergency_contact_name,
            'emergency_contact_phone' => $creds?->emergency_contact_phone,
            'emergency_contact_relationship' => $creds?->emergency_contact_relationship,
            'medical_conditions' => $creds?->medical_conditions,
            'dl_number' => $creds?->dl_number,
            'dl_state' => $creds?->dl_state,
            'dl_expiry' => $creds?->dl_expiry?->format('Y-m-d'),
            'dl_document_id' => $creds?->dl_document_id,
            'dl_document_id_back' => $creds?->dl_document_id_back,
            'dl_confirmed_current' => true,
            'hunting_license_number' => $creds?->hunting_license_number,
            'hunting_license_state' => $creds?->hunting_license_state,
            'hunting_license_expiry' => $creds?->hunting_license_expiry?->format('Y-m-d'),
            'hunting_license_document_id' => $creds?->hunting_license_document_id,
            'hunting_license_document_id_back' => $creds?->hunting_license_document_id_back,
            'hunting_license_confirmed_current' => true,
        ];
    }

    /**
     * The mobile client can't re-enter the roster, so the saved credentials must be
     * complete. Fail with a clear list of what's missing rather than submitting a
     * half-filled application the landowner can't vet.
     */
    private function assertCredentialsComplete(array $hunter): void
    {
        $required = [
            'first_name', 'last_name', 'date_of_birth', 'email', 'cell_phone',
            'address_line1', 'city', 'state_code', 'zip_code',
            'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relationship',
            'dl_number', 'dl_state', 'dl_expiry',
            'hunting_license_number', 'hunting_license_state', 'hunting_license_expiry',
        ];

        $missing = array_values(array_filter($required, fn ($f) => blank($hunter[$f] ?? null)));

        if ($missing) {
            throw ValidationException::withMessages([
                'credentials' => 'Complete your hunter credentials before applying. Missing: '.implode(', ', $missing).'.',
            ]);
        }

        $today = now()->toDateString();
        if ($hunter['dl_expiry'] < $today) {
            throw ValidationException::withMessages(['dl_expiry' => 'Your driver\'s license has expired. Update it before applying.']);
        }
        if ($hunter['hunting_license_expiry'] < $today) {
            throw ValidationException::withMessages(['hunting_license_expiry' => 'Your hunting license has expired. Update it before applying.']);
        }
    }

    private function assertLicenceMatchesProperty(array $hunter, object $listing): void
    {
        $state = $listing->property?->state_code;

        if ($state && $hunter['hunting_license_state'] !== $state) {
            throw ValidationException::withMessages([
                'hunting_license_state' => "Hunting license must be issued by {$state} — the state this property is in.",
            ]);
        }
    }

    /**
     * Resolve the lease term. Fixed-term listings use the season (client dates are
     * ignored — a race can't have per-applicant terms); day-hunt listings require a
     * client-chosen range inside the season that doesn't overlap a blocked range.
     *
     * @return array{0: string, 1: string}
     */
    private function resolveTerm(object $listing, ?string $start, ?string $end): array
    {
        $seasonStart = $listing->season_start?->toDateString();
        $seasonEnd = $listing->season_end?->toDateString();
        $today = now()->toDateString();

        if (in_array($listing->listing_type, ['annual_lease', 'seasonal_lease'], true)) {
            if ($seasonEnd && $seasonEnd < $today) {
                throw ValidationException::withMessages(['listing' => 'This listing\'s season has ended and is no longer accepting applications.']);
            }

            return [$seasonStart, $seasonEnd];
        }

        // day_hunt (and any other range-based type): client picks the dates.
        if (blank($start) || blank($end)) {
            throw ValidationException::withMessages(['proposed_start' => 'Choose the dates you want to hunt.']);
        }
        if ($end <= $start) {
            throw ValidationException::withMessages(['proposed_end' => 'The end date must be after the start date.']);
        }
        if ($seasonStart && $start < $seasonStart) {
            throw ValidationException::withMessages(['proposed_start' => 'The start date must fall within the listing\'s available season.']);
        }
        if ($seasonEnd && $end > $seasonEnd) {
            throw ValidationException::withMessages(['proposed_end' => 'The end date must fall within the listing\'s available season.']);
        }

        foreach ($this->properties->getUnavailableRanges($listing->id) as $range) {
            if ($start <= $range['end'] && $end >= $range['start']) {
                throw ValidationException::withMessages(['proposed_start' => 'Those dates are not available — part of the range is already booked or blocked. Please pick open dates.']);
            }
        }

        return [$start, $end];
    }

    private function serializeListing(object $listing): array
    {
        return [
            'id' => $listing->id,
            'listing_type' => $listing->listing_type,
            'status' => $listing->status,
            'season_start' => $listing->season_start?->toDateString(),
            'season_end' => $listing->season_end?->toDateString(),
            'min_hunters' => $listing->min_hunters,
            'max_hunters' => $listing->max_hunters,
            'price_per_hunter' => $listing->price_per_hunter,
            'price_total' => $listing->price_total,
            'deposit_percent' => $listing->deposit_percent,
            'deposit_amount' => $listing->deposit_amount,
        ];
    }

    private function serializeProperty(object $property): array
    {
        return [
            'id' => $property->id,
            'title' => $property->title,
            'slug' => $property->slug,
            'state_code' => $property->state_code,
            'county' => $property->county,
            'total_acres' => $property->total_acres,
        ];
    }
}
