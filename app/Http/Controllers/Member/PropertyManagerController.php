<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Services\Lease\CheckInService;
use App\Services\Property\PropertyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Landowner front-end team & activity (member portal), Slice 5 — the Managers and
 * Check In/Out tabs from the admin PropertyFormV2. Managers can be granted (by
 * email) and revoked; the check-in log is read-only. Scoped through
 * PropertyService::userCanManageProperty (the properties table has no RLS policy)
 * and revoke is additionally scoped to the property so a foreign grant id 404s
 * into a no-op.
 */
class PropertyManagerController extends Controller
{
    public function __construct(
        private readonly PropertyService $properties,
        private readonly CheckInService $checkIns,
    ) {}

    private const ROLES = [
        'owner'    => 'Owner',
        'co_owner' => 'Co-Owner',
        'manager'  => 'Manager',
        'operator' => 'Operator',
    ];

    public function index(string $property): Response
    {
        $record = $this->authorizeManage($property);

        return Inertia::render('Member/Properties/Team', [
            'property' => [
                'id'    => $record->id,
                'title' => $record->title,
            ],
            'managers' => $this->properties->getManagersForProperty($property),
            'roles'    => self::ROLES,
            'checkIns' => $this->presentCheckIns($property),
        ]);
    }

    public function store(Request $request, string $property): RedirectResponse
    {
        $this->authorizeManage($property);

        $data = $request->validate([
            'user_email' => 'required|email',
            'role'       => ['required', Rule::in(array_keys(self::ROLES))],
        ]);

        $result = $this->properties->grantManager(
            $property,
            $data['user_email'],
            $data['role'],
            session('auth.user_id'),
        );

        if (! $result['ok']) {
            throw ValidationException::withMessages(['user_email' => $result['message']]);
        }

        return redirect()
            ->route('member.properties.team.index', $property)
            ->with('success', $result['message']);
    }

    public function destroy(string $property, string $manager): RedirectResponse
    {
        $this->authorizeManage($property);

        $ok = $this->properties->revokeManager($property, $manager);

        return redirect()
            ->route('member.properties.team.index', $property)
            ->with($ok ? 'success' : 'error', $ok
                ? 'Manager access revoked.'
                : 'Manager not found or already revoked.');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function presentCheckIns(string $propertyId): array
    {
        return array_map(fn (array $r) => [
            'name'           => $r['name'],
            'email'          => $r['email'],
            'lease_ref'      => $r['lease_ref'],
            'checked_in_at'  => $r['checked_in_at']?->format('M j, Y g:i A'),
            'checked_out_at' => $r['checked_out_at']?->format('M j, Y g:i A'),
            'open'           => $r['open'],
        ], $this->checkIns->getHistoryForProperty($propertyId));
    }

    /** Resolve a property the current user owns or actively manages, or 404. */
    private function authorizeManage(string $propertyId)
    {
        $userId = session('auth.user_id');
        abort_unless($this->properties->userCanManageProperty($userId, $propertyId), 404);

        return $this->properties->find($propertyId) ?? abort(404);
    }
}
