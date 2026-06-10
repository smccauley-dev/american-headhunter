<?php

namespace App\Models\Identity;

use App\Models\BaseModel;

class HunterCredentials extends BaseModel
{
    protected $connection = 'identity';
    protected $table      = 'hunter_credentials';

    protected $fillable = [
        'user_id',
        'address_line1',
        'address_line2',
        'city',
        'state_code',
        'zip_code',
        'home_phone',
        'cell_phone',
        'emergency_contact_name',
        'emergency_contact_phone',
        'emergency_contact_relationship',
        'medical_conditions',
        'dl_number',
        'dl_state',
        'dl_expiry',
        'dl_document_id',
        'dl_document_id_back',
        'hunting_license_number',
        'hunting_license_state',
        'hunting_license_expiry',
        'hunting_license_document_id',
        'hunting_license_document_id_back',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'dl_expiry'              => 'date',
            'hunting_license_expiry' => 'date',
        ]);
    }

    public function hasDl(): bool
    {
        return $this->dl_number !== null && $this->dl_number !== '';
    }

    public function hasHuntingLicense(): bool
    {
        return $this->hunting_license_number !== null && $this->hunting_license_number !== '';
    }
}
