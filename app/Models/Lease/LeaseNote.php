<?php

namespace App\Models\Lease;

use App\Models\BaseModelWithSoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaseNote extends BaseModelWithSoftDeletes
{
    protected $connection = 'lease';
    protected $table      = 'lease_notes';

    protected $fillable = [
        'lease_id',
        'author_user_id',
        'note',
        'is_internal',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'is_internal' => 'boolean',
        ]);
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class, 'lease_id');
    }

    public function getAuthor(): ?\App\Models\Identity\User
    {
        return app(\App\Services\Identity\UserService::class)->findById($this->author_user_id);
    }
}
