<?php

namespace App\Models\Documents;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// No deleted_at — completed jobs retained for re-download
class PrintJob extends BaseModel
{
    protected $connection = 'documents';
    protected $table      = 'print_jobs';

    protected $fillable = [
        'job_type',
        'owner_user_id',
        'status',
        'target_id',
        'target_type',
        'document_id',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), []);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function isReady(): bool
    {
        return $this->status === 'ready';
    }
}
