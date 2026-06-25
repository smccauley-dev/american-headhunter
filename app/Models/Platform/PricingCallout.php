<?php

namespace App\Models\Platform;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A publishable horizontal banner shown beneath the plan cards on a pricing tab
 * (DB 12). Not a purchasable plan — just copy, optional feature bullets, and one
 * or more CTA buttons, gated by is_published. See the pricing_callouts migration.
 *
 * May optionally reference a membership plan (same DB) purely to surface that
 * plan's live price on the banner — the link is display-only and grants nothing.
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
        'buttons',
        'accent_color',
        'is_published',
        'sort_order',
        'plan_id',
    ];

    /**
     * Optional same-database link used only to display the plan's live price.
     * Not a cross-DB reference — membership_plans lives in this connection.
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'plan_id');
    }

    protected function casts(): array
    {
        return [
            'features'     => 'array',
            'buttons'      => 'array',
            'is_published' => 'boolean',
            'sort_order'   => 'integer',
            'created_at'   => 'datetime',
            'updated_at'   => 'datetime',
        ];
    }
}
