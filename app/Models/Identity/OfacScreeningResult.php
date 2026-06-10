<?php

namespace App\Models\Identity;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfacScreeningResult extends BaseModel
{
    protected $connection = 'identity';
    protected $table      = 'ofac_screening_results';

    protected $fillable = [
        'user_id',
        'status',
        'screened_at',
        'next_screening_at',
    ];

    protected $hidden = ['match_details_encrypted'];

    protected function casts(): array
    {
        return [
            'screened_at'      => 'datetime',
            'next_screening_at' => 'datetime',
            'created_at'       => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isClear(): bool
    {
        return $this->status === 'clear';
    }

    public function isMatch(): bool
    {
        return $this->status === 'match';
    }
}
