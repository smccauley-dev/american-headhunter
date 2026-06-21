<?php

namespace App\Models\Lease;

use App\Models\BaseModel;
use App\Models\Traits\HasEncryptedFields;

class LeaseApplicationHunter extends BaseModel
{
    use HasEncryptedFields;

    protected $connection = 'lease';
    protected $table      = 'lease_application_hunters';

    // -- encrypted via pgp_sym_encrypt (Key C)
    protected array $encryptedFields = [
        'email',
        'home_phone',
        'cell_phone',
        'address_line1',
        'address_line2',
        'city',
        'emergency_contact_name',
        'emergency_contact_phone',
        'emergency_contact_relationship',
        'medical_conditions',
        'dl_number',
        'hunting_license_number',
    ];

    // Immutable snapshot — no updated_at, no deleted_at
    public $timestamps = false;

    protected $fillable = [
        'application_id',
        'hunter_type',
        'user_id',
        'guest_hunter_id',
        'first_name',
        'last_name',
        'date_of_birth',
        'is_minor',
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
        'dl_document_id_back',
        'dl_confirmed_current',
        'hunting_license_number',
        'hunting_license_state',
        'hunting_license_expiry',
        'hunting_license_document_id',
        'hunting_license_document_id_back',
        'hunting_license_confirmed_current',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth'                    => 'date',
            'is_minor'                         => 'boolean',
            'dl_expiry'                        => 'date',
            'dl_confirmed_current'             => 'boolean',
            'hunting_license_expiry'           => 'date',
            'hunting_license_confirmed_current' => 'boolean',
            'created_at'                       => 'datetime',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        // created_at must be set manually since timestamps = false
        static::creating(function (self $model): void {
            if ($model->created_at === null) {
                $model->created_at = now();
            }
        });
    }

    public function application(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(LeaseApplication::class, 'application_id');
    }
}
