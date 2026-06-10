<?php

namespace App\Http\Resources\Identity;

use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    // Expects User with 'profile' and 'credentials' eager-loaded via User::with([...]).
    public function toArray($request): array
    {
        return [
            'id'             => $this->id,
            'email'          => $this->email,
            'phone'          => $this->phone,
            'account_type'   => $this->account_type,
            'is_veteran'     => $this->is_veteran,
            // Derived: first_responder status lives in user_profiles, not users.
            'is_first_responder' => $this->profile?->first_responder_type !== null,
            'email_verified' => $this->email_verified_at !== null,
            'phone_verified' => $this->phone_verified_at !== null,
            'profile'        => $this->whenLoaded('profile', fn() =>
                $this->profile ? [
                    'first_name'      => $this->profile->first_name,
                    'last_name'       => $this->profile->last_name,
                    'display_name'    => $this->profile->display_name,
                    'bio'             => $this->profile->bio,
                    'state_code'      => $this->profile->state_code,
                    'zip_code'        => $this->profile->zip_code,
                    'date_of_birth'   => $this->profile->date_of_birth?->toDateString(),
                    'hunting_profile' => $this->profile->hunting_profile,
                    'notification_preferences' => $this->profile->notification_preferences,
                    // Avatar: document_id only — URL resolution wired in Step 3.
                    'avatar_document_id' => $this->profile->avatar_document_id,
                    // Veteran detail — shown on hunter's own profile only.
                    'veteran' => $this->when($this->is_veteran, fn() => [
                        'branch'     => $this->profile->veteran_branch,
                        'last_rank'  => $this->profile->veteran_last_rank,
                        'is_active'  => $this->profile->veteran_is_active,
                        'bio'        => $this->profile->veteran_bio,
                    ]),
                    'first_responder' => $this->when(
                        $this->profile->first_responder_type !== null,
                        fn() => [
                            'type'      => $this->profile->first_responder_type,
                            'last_rank' => $this->profile->first_responder_last_rank,
                            'is_active' => $this->profile->first_responder_is_active,
                            'bio'       => $this->profile->first_responder_bio,
                        ]
                    ),
                ] : null
            ),
            // Credentials: hunter's own PII — never included in list/index responses.
            // HunterCredentials fields are plain text in this table (no HasEncryptedFields).
            'credentials'    => $this->whenLoaded('credentials', fn() =>
                $this->credentials ? [
                    'dl_state'                  => $this->credentials->dl_state,
                    'dl_expiry'                 => $this->credentials->dl_expiry?->toDateString(),
                    'dl_number'                 => $this->credentials->dl_number,
                    'dl_document_id'            => $this->credentials->dl_document_id,
                    'dl_document_id_back'       => $this->credentials->dl_document_id_back,
                    'hunting_license_state'     => $this->credentials->hunting_license_state,
                    'hunting_license_expiry'    => $this->credentials->hunting_license_expiry?->toDateString(),
                    'hunting_license_number'    => $this->credentials->hunting_license_number,
                    'hunting_license_document_id' => $this->credentials->hunting_license_document_id,
                ] : null
            ),
        ];
    }
}
