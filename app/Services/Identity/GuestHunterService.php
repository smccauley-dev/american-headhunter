<?php

namespace App\Services\Identity;

use App\Models\Identity\GuestHunter;
use App\Services\BaseService;
use Illuminate\Support\Collection;

class GuestHunterService extends BaseService
{
    public function getForUser(string $userId): Collection
    {
        return GuestHunter::where('owner_user_id', $userId)
            ->whereNull('deleted_at')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();
    }

    public function createOrUpdate(?string $guestHunterId, string $userId, array $data): GuestHunter
    {
        if ($guestHunterId) {
            $guest = GuestHunter::where('id', $guestHunterId)
                ->where('owner_user_id', $userId)
                ->whereNull('deleted_at')
                ->first();

            if ($guest) {
                $guest->update($data);
                return $guest->refresh();
            }
        }

        return GuestHunter::create(array_merge($data, ['owner_user_id' => $userId]));
    }

    public function serializeForForm(GuestHunter $guest): array
    {
        return [
            'id'                             => $guest->id,
            'first_name'                     => $guest->first_name,
            'last_name'                      => $guest->last_name,
            'date_of_birth'                  => $guest->date_of_birth?->format('Y-m-d'),
            'email'                          => $guest->email,
            'home_phone'                     => $guest->home_phone,
            'cell_phone'                     => $guest->cell_phone,
            'address_line1'                  => $guest->address_line1,
            'address_line2'                  => $guest->address_line2,
            'city'                           => $guest->city,
            'state_code'                     => $guest->state_code,
            'zip_code'                       => $guest->zip_code,
            'emergency_contact_name'         => $guest->emergency_contact_name,
            'emergency_contact_phone'        => $guest->emergency_contact_phone,
            'emergency_contact_relationship' => $guest->emergency_contact_relationship,
            'medical_conditions'             => $guest->medical_conditions,
            'dl_number'                      => $guest->dl_number,
            'dl_state'                       => $guest->dl_state,
            'dl_expiry'                      => $guest->dl_expiry?->format('Y-m-d'),
            'hunting_license_number'         => $guest->hunting_license_number,
            'hunting_license_state'          => $guest->hunting_license_state,
            'hunting_license_expiry'         => $guest->hunting_license_expiry?->format('Y-m-d'),
        ];
    }
}
