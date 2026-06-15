<?php

namespace App\Models\Billing;

use App\Models\BaseModel;
use App\Models\Traits\HasEncryptedFields;

class W9Record extends BaseModel
{
    use HasEncryptedFields;

    protected $connection = 'billing';
    protected $table      = 'w9_records';

    // -- encrypted via pgp_sym_encrypt base64 (Key D / ENCRYPTION_KEY_BILLING)
    protected array $encryptedFields = ['tin'];

    // Never expose the (decrypted) TIN in API/array output. tin_last_four is display-safe.
    protected $hidden = ['tin'];

    protected $fillable = [
        'user_id',
        'legal_name',
        'business_name',
        'tax_classification',
        'tin_type',
        'tin',
        'tin_last_four',
        'address_line1',
        'address_line2',
        'city',
        'state_code',
        'postal_code',
        'backup_withholding',
        'status',
        'certified_at',
        'verified_at',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'backup_withholding' => 'boolean',
            'certified_at'       => 'datetime',
            'verified_at'        => 'datetime',
        ]);
    }
}
