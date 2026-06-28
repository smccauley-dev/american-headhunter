<?php

namespace App\Models\Billing;

use App\Models\BaseModelWithSoftDeletes;

/**
 * A configurable processing-fee rule (DB 4). Resolved by FeeService to compute the
 * customer-facing surcharge on a transaction. System-authored (Filament admin under
 * ah_system); runtime-readable via RLS. See the create migration for the security
 * model and the resolution rule (most-specific state wins).
 */
class FeeSchedule extends BaseModelWithSoftDeletes
{
    protected $connection = 'billing';

    protected $table = 'fee_schedules';

    protected $fillable = [
        'transaction_category',
        'state_code',
        'pct',
        'flat_cents',
        'payer',
        'description',
        'is_active',
        'gross_up',
        'effective_from',
        'effective_to',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'pct' => 'float',
            'flat_cents' => 'integer',
            'is_active' => 'boolean',
            'gross_up' => 'boolean',
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
        ]);
    }
}
