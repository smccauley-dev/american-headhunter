<?php

namespace App\Models\Billing;

use App\Models\BaseModel;

class Tax1099Record extends BaseModel
{
    protected $connection = 'billing';
    protected $table      = 'tax_1099_records';

    protected $fillable = [
        'payee_user_id',
        'tax_year',
        'form_type',
        'gross_amount_cents',
        'status',
        'tax1099_record_id',
        'filed_at',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'tax_year'           => 'integer',
            'gross_amount_cents' => 'integer',
            'filed_at'           => 'datetime',
        ]);
    }
}
