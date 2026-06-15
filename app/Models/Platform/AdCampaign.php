<?php

namespace App\Models\Platform;

use App\Models\BaseModelWithSoftDeletes;

class AdCampaign extends BaseModelWithSoftDeletes
{
    protected $connection = 'platform';
    protected $table      = 'ad_campaigns';

    protected $fillable = [
        'name',
        'advertiser_user_id',
        'target_states',
        'target_species',
        'budget_cents',
        'spend_cents',
        'cpm_bid_cents',
        'starts_at',
        'ends_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'target_states' => 'array',
            'target_species' => 'array',
            'budget_cents'  => 'integer',
            'spend_cents'   => 'integer',
            'cpm_bid_cents' => 'integer',
            'is_active'     => 'boolean',
            'starts_at'     => 'datetime',
            'ends_at'       => 'datetime',
            'created_at'    => 'datetime',
            'updated_at'    => 'datetime',
            'deleted_at'    => 'datetime',
        ];
    }
}
