<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Models\Property\PropertyContact;
use App\Services\Property\PropertyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Landowner front-end property contacts (member portal) — the Contacts tab of the
 * details hub. Two kinds of contact: managers promoted to field contacts (the
 * grant lives on property_managers, toggled via is_field_contact) and standalone
 * emergency/local contacts (PropertyContact rows). All scoped through
 * PropertyService::userCanManageProperty (the properties table has no RLS policy);
 * single-row mutations are re-scoped to the property.
 */
class PropertyContactController extends Controller
{
    public function __construct(private readonly PropertyService $properties) {}

    public function addManager(Request $request, string $property): RedirectResponse
    {
        $this->authorizeManage($property);

        $data = $request->validate(['manager_id' => 'required|string']);

        $ok = $this->properties->addManagerContact($property, $data['manager_id']);

        return back()->with(
            $ok ? 'success' : 'error',
            $ok ? 'Manager added as a contact.' : 'That manager is no longer available.',
        );
    }

    public function removeManager(string $property, string $manager): RedirectResponse
    {
        $this->authorizeManage($property);

        $ok = $this->properties->removeManagerContact($property, $manager);

        return back()->with(
            $ok ? 'success' : 'error',
            $ok ? 'Manager removed from contacts.' : 'Contact not found.',
        );
    }

    public function store(Request $request, string $property): RedirectResponse
    {
        $this->authorizeManage($property);

        $this->properties->addContact($property, $this->validatedContact($request));

        return back()->with('success', 'Contact added.');
    }

    public function update(Request $request, string $property, string $contact): RedirectResponse
    {
        $this->authorizeManage($property);

        $ok = $this->properties->updateContact($property, $contact, $this->validatedContact($request));

        return back()->with(
            $ok ? 'success' : 'error',
            $ok ? 'Contact updated.' : 'Contact not found.',
        );
    }

    public function destroy(string $property, string $contact): RedirectResponse
    {
        $this->authorizeManage($property);

        $ok = $this->properties->deleteContact($property, $contact);

        return back()->with(
            $ok ? 'success' : 'error',
            $ok ? 'Contact deleted.' : 'Contact not found.',
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function validatedContact(Request $request): array
    {
        return $request->validate([
            'contact_type' => ['required', Rule::in(array_keys(PropertyContact::TYPES))],
            'label'        => 'nullable|string|max:120',
            'name'         => 'nullable|string|max:160',
            'organization' => 'nullable|string|max:160',
            'phone'        => 'nullable|string|max:40',
            'email'        => 'nullable|email|max:160',
            'address'      => 'nullable|string|max:255',
            'notes'        => 'nullable|string|max:500',
        ]);
    }

    private function authorizeManage(string $propertyId): void
    {
        $userId = session('auth.user_id');
        abort_unless($this->properties->userCanManageProperty($userId, $propertyId), 404);
    }
}
