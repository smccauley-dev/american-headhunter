<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Identity\User;
use App\Models\Identity\UserProfile;
use App\Models\Lease\LeaseApplication;
use App\Models\Lease\LeaseApplicationHunter;
use App\Models\Lease\LeaseApplicationMessage;
use App\Services\Property\PropertyService;
use App\Support\AdminAuth;
use Illuminate\Http\Response;

class PrintApplicationController extends Controller
{
    public function __construct(
        private readonly PropertyService $propertyService,
    ) {}

    public function show(string $applicationId): Response
    {
        abort_unless(auth()->check() && AdminAuth::canManageLeases(), 403);

        $application = LeaseApplication::where('id', $applicationId)
            ->whereNull('deleted_at')
            ->firstOrFail();

        $listing  = $this->propertyService->findListing($application->listing_id);
        $property = $listing?->property;

        // Query identity DB directly — bypass Valkey cache to avoid deserialization issues
        $applicant        = User::on('identity')->find($application->applicant_user_id);
        $applicantProfile = $applicant
            ? UserProfile::on('identity')->where('user_id', $applicant->id)->first()
            : null;
        $applicantName  = $applicantProfile
            ? trim("{$applicantProfile->first_name} {$applicantProfile->last_name}")
            : null;

        $hunters = LeaseApplicationHunter::where('application_id', $applicationId)
            ->orderByRaw("hunter_type = 'primary' DESC")
            ->orderBy('created_at')
            ->get();

        $messages = LeaseApplicationMessage::where('application_id', $applicationId)
            ->orderBy('created_at')
            ->get();

        $reviewedByName = null;
        if ($application->reviewed_by_user_id) {
            $reviewerProfile = UserProfile::on('identity')
                ->where('user_id', $application->reviewed_by_user_id)
                ->first();
            $reviewedByName = $reviewerProfile
                ? trim("{$reviewerProfile->first_name} {$reviewerProfile->last_name}")
                : strtoupper(substr($application->reviewed_by_user_id, 0, 8));
        }

        // Snapshot fallbacks — used when listing/property is soft-deleted or archived
        $displayTitle    = $property?->title    ?? $application->property_title_snapshot;
        $displayLocation = $property
            ? "{$property->county} County, {$property->state_code}"
            : $application->property_location_snapshot;

        return response()->view('admin.print-application', [
            'application'     => $application,
            'listing'         => $listing,
            'property'        => $property,
            'displayTitle'    => $displayTitle,
            'displayLocation' => $displayLocation,
            'applicantName'   => $applicantName,
            'applicantEmail'  => $applicant?->email,
            'hunters'         => $hunters,
            'messages'        => $messages,
            'reviewedByName'  => $reviewedByName,
            'printedAt'       => now()->format('F j, Y \a\t g:i A'),
            'printedBy'       => auth()->user()?->name ?? auth()->user()?->email ?? 'Admin',
        ]);
    }
}
