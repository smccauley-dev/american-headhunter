<?php

namespace App\Models\Platform;

use App\Models\BaseModel;

/**
 * A publishable horizontal banner shown beneath the plan cards on a pricing tab
 * (DB 12). Not a purchasable plan — just copy, optional feature bullets, and a
 * single CTA link, gated by is_published. See the pricing_callouts migration.
 */
class PricingCallout extends BaseModel
{
    protected $connection = 'platform';
    protected $table      = 'pricing_callouts';

    protected $fillable = [
        'account_type',
        'eyebrow',
        'body',
        'features',
        'cta_label',
        'cta_url',
        'accent_color',
        'is_published',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'features'     => 'array',
            'is_published' => 'boolean',
            'sort_order'   => 'integer',
            'created_at'   => 'datetime',
            'updated_at'   => 'datetime',
        ];
    }
}
