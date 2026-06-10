<?php

namespace App\Models\Lease;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClubLease extends BaseModel
{
    protected $connection = 'lease';
    protected $table      = 'club_leases';

    protected $fillable = [
        'club_id',
        'lease_id',
    ];

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class, 'club_id');
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class, 'lease_id');
    }
}
