<?php

namespace App\Models\Lease;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaseApplicationReviewHistory extends BaseModel
{
    protected $connection = 'lease';
    protected $table      = 'lease_application_review_history';

    public $timestamps = false;

    protected $fillable = [
        'application_id',
        'decided_by_user_id',
        'from_status',
        'to_status',
        'reason',
    ];

    protected function casts(): array
    {
        return [
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

    public function isOverride(): bool
    {
        return $this->from_status !== null;
    }

    public function label(): string
    {
        if ($this->from_status === null) {
            return $this->to_status === 'approved' ? 'Approved' : 'Rejected';
        }

        return match ([$this->from_status, $this->to_status]) {
            ['approved', 'rejected'] => 'Override — Approval Revoked',
            ['rejected', 'approved'] => 'Override — Rejection Reversed',
            default                  => 'Override',
        };
    }
}
