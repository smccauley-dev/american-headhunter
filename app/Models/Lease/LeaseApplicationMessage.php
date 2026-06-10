<?php

namespace App\Models\Lease;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaseApplicationMessage extends BaseModel
{
    protected $connection = 'lease';
    protected $table      = 'lease_application_messages';

    public $timestamps = false;

    protected $fillable = [
        'application_id',
        'sender_user_id',
        'sender_role',
        'message',
        'is_read',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'is_read'    => 'boolean',
            'read_at'    => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($m) => $m->created_at ??= now());
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(LeaseApplication::class, 'application_id');
    }
}
