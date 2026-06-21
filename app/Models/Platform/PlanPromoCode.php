<?php

namespace App\Models\Platform;

use App\Models\BaseModel;

/**
 * Pivot linking a membership plan (DB 12) to a promo code (DB 4 — cross-DB UUID
 * ref, no FK). A row's existence restricts the code to this plan; the
 * show_on_pricing_card flag both advertises the code on the public pricing card
 * and auto-applies its discount at checkout.
 */
class PlanPromoCode extends BaseModel
{
    protected $connection = 'platform';
    protected $table      = 'plan_promo_codes';

    protected $fillable = [
        'plan_id',
        'promo_code_id',
        'show_on_pricing_card',
    ];

    protected function casts(): array
    {
        return [
            'show_on_pricing_card' => 'boolean',
            'created_at'           => 'datetime',
            'updated_at'           => 'datetime',
        ];
    }
}
