<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Services\Property\PropertyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Landowner front-end managers (member portal) — the Team tab of the details hub.
 * Managers can be granted (by email) and revoked. Scoped through
 * PropertyService::userCanManageProperty (the properties table has no RLS policy)
 * and revoke is additionally scoped to the property so a foreign grant id 404s
 * into a no-op. Redirects back so the active tab is preserved.
 */
class PropertyManagerController extends Controller
{
    public function __construct(private readonly PropertyService $properties) {}

    private const ROLES = [
        'owner'    => 'Owner',
        'co_owner' => 'Co-Owner',
        'manager'  => 'Manager',
        'operator' => 'Operator',
    ];

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

        return back()->with('success', $result['message']);
    }

    public function destroy(string $property, string $manager): RedirectResponse
    {
        $this->authorizeManage($property);

        $ok = $this->properties->revokeManager($property, $manager);

        return back()->with($ok ? 'success' : 'error', $ok
            ? 'Manager access revoked.'
            : 'Manager not found or already revoked.');
    }

    /** Resolve a property the current user owns or actively manages, or 404. */
    private function authorizeManage(string $propertyId): void
    {
        $userId = session('auth.user_id');
        abort_unless($this->properties->userCanManageProperty($userId, $propertyId), 404);
    }
}
