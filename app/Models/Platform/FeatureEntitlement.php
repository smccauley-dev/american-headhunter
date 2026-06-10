<?php

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;

class FeatureEntitlement extends Model
{
    protected $connection = 'platform';
    protected $table      = 'feature_entitlements';

    public $incrementing = false;
    protected $keyType   = 'string';
    public $timestamps   = false;

    protected $fillable = [
        'plan_id',
        'feature_key',
        'feature_type',
        'bool_value',
        'int_value',
        'string_value',
        'json_value',
        'display_label',
        'display_description',
        'display_order',
        'show_on_pricing',
    ];

    protected function casts(): array
    {
        return [
            'bool_value'      => 'boolean',
            'int_value'       => 'integer',
            'json_value'      => 'array',
            'display_order'   => 'integer',
            'show_on_pricing' => 'boolean',
            'created_at'      => 'datetime',
            'updated_at'      => 'datetime',
        ];
    }

    public function plan(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'plan_id');
    }

    public function value(): mixed
    {
        return match ($this->feature_type) {
            'boolean' => $this->bool_value,
            'integer' => $this->int_value,
            'string'  => $this->string_value,
            'json'    => $this->json_value,
            default   => null,
        };
    }
}
