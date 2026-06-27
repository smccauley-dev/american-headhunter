<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Models\Identity\User;
use App\Models\Property\PropertyOwnershipVerification;
use App\Services\Property\PropertyService;
use App\Support\UsStates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Landowner front-end property management (member portal). Scoped entirely by
 * PropertyService::userCanManageProperty — the `properties` table carries no
 * RLS policy, so ownership is enforced here, not by the database.
 *
 * Slice 2: General Info fields only (parity with the admin PropertyFormV2
 * "General Info" tab). Listings, species, photos, map, etc. arrive in later
 * slices.
 */
class PropertyController extends Controller
{
    public function __construct(private readonly PropertyService $properties) {}

    private const STATUS_OPTIONS = [
        'draft'     => 'Draft',
        'active'    => 'Active',
        'suspended' => 'Suspended',
        'archived'  => 'Archived',
    ];

    public function create(): Response
    {
        $this->authorizeCreate();

        return Inertia::render('Member/Properties/Form', [
            'property' => null,
            'states'   => UsStates::names(),
            'statuses' => self::STATUS_OPTIONS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeCreate();

        $data = $this->validated($request);

        // A brand-new property has no approved proof of ownership yet, so it cannot
        // start Active. Create it as a draft and surface why.
        if ($data['status'] === 'active') {
            return back()->withErrors([
                'status' => 'A new property starts as a draft. Submit proof of ownership and have it approved before going Active.',
            ])->withInput();
        }

        $property = $this->properties->createProperty(session('auth.user_id'), $data);

        return redirect()
            ->route('member.properties.edit', $property->id)
            ->with('status', 'Property created.');
    }

    public function edit(string $property): Response
    {
        $record = $this->authorizeManage($property);

        return Inertia::render('Member/Properties/Form', [
            'property' => [
                'id'             => $record->id,
                'title'          => $record->title,
                'description'    => $record->description,
                'status'         => $record->status,
                'state_code'     => $record->state_code,
                'county'         => $record->county,
                'center_lat'     => $record->center_lat,
                'center_lng'     => $record->center_lng,
                'total_acres'    => $record->total_acres !== null ? (float) $record->total_acres : null,
                'huntable_acres' => $record->huntable_acres !== null ? (float) $record->huntable_acres : null,
            ],
            'states'         => UsStates::names(),
            'statuses'       => self::STATUS_OPTIONS,
            'ownership'      => $this->properties->getOwnershipVerification($record->id),
            'ownerTypes'     => PropertyOwnershipVerification::OWNER_TYPES,
            'suggestedProof' => PropertyOwnershipVerification::SUGGESTED_PROOF,
        ]);
    }

    public function update(Request $request, string $property): RedirectResponse
    {
        $this->authorizeManage($property);

        $data = $this->validated($request);

        // Going Active requires staff-approved proof of ownership (gates listing live).
        if ($data['status'] === 'active' && ! $this->properties->hasApprovedOwnership($property)) {
            return back()->withErrors([
                'status' => 'This property can go Active only after your proof of ownership is approved by staff.',
            ])->withInput();
        }

        $this->properties->updateProperty($property, $data);

        return redirect()
            ->route('member.properties.edit', $property)
            ->with('status', 'Property saved.');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function validated(Request $request): array
    {
        return $request->validate([
            'title'          => 'required|string|max:255',
            'description'    => 'nullable|string|max:5000',
            'status'         => ['required', Rule::in(array_keys(self::STATUS_OPTIONS))],
            'state_code'     => ['required', 'string', 'size:2', Rule::in(array_keys(UsStates::names()))],
            'county'         => 'required|string|max:100',
            'center_lat'     => 'nullable|numeric|between:-90,90',
            'center_lng'     => 'nullable|numeric|between:-180,180',
            'total_acres'    => 'required|numeric|min:1',
            'huntable_acres' => 'nullable|numeric|min:0',
        ]);
    }

    /** Creating a property is a landowner-only action (the entry point lives on the landowner profile). */
    private function authorizeCreate(): void
    {
        $user = User::findOrFail(session('auth.user_id'));
        abort_unless($user->account_type === 'landowner', 403);
    }

    /** Resolve a property the current user owns or actively manages, or 404. */
    private function authorizeManage(string $propertyId)
    {
        $userId = session('auth.user_id');
        abort_unless($this->properties->userCanManageProperty($userId, $propertyId), 404);

        return $this->properties->find($propertyId) ?? abort(404);
    }
}
