<?php

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;

// Intentionally extends the raw Model, NOT BaseModel: the primary key is the
// known string `factor` ('totp', 'sms', …), not a generated UUID `id`, so the
// BaseModel UUID-on-creating hook does not apply here.
class MfaFactorSetting extends Model
{
    protected $connection = 'platform';
    protected $table      = 'mfa_factor_settings';

    protected $primaryKey = 'factor';
    public    $incrementing = false;
    protected $keyType      = 'string';

    // Timestamps managed by DB trigger; Eloquent must not try to set created_at.
    public    $timestamps = false;

    protected $fillable = ['is_enabled'];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'updated_at' => 'datetime',
        ];
    }
}
