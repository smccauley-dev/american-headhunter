<?php

namespace App\Models\Identity;

use App\Models\BaseModelWithSoftDeletes;

class GuestHunter extends BaseModelWithSoftDeletes
{
    protected $connection = 'identity';
    protected $table      = 'guest_hunters';

    protected $fillable = [
        'owner_user_id',
        'first_name',
        'last_name',
        'date_of_birth',
        'email',
        'home_phone',
        'cell_phone',
        'address_line1',
        'address_line2',
        'city',
        'state_code',
        'zip_code',
        'emergency_contact_name',
        'emergency_contact_phone',
        'emergency_contact_relationship',
        'medical_conditions',
        'dl_number',
        'dl_state',
        'dl_expiry',
        'dl_document_id',
        'hunting_license_number',
        'hunting_license_state',
        'hunting_license_expiry',
        'hunting_license_document_id',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'date_of_birth'          => 'date',
            'dl_expiry'              => 'date',
            'hunting_license_expiry' => 'date',
        ]);
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function isMinor(): bool
    {
        return $this->date_of_birth !== null
            && $this->date_of_birth->age < 18;
    }
}
