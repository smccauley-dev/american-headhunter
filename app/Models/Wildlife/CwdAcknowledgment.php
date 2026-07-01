<?php

namespace App\Models\Wildlife;

use App\Models\BaseModel;

/**
 * Append-only CWD compliance record — one row per (harvest, positive zone).
 * The table has only created_at (no updated_at / deleted_at): acknowledgments
 * are never edited or removed once written.
 */
class CwdAcknowledgment extends BaseModel
{
    protected $connection = 'wildlife';

    protected $table = 'cwd_acknowledgments';

    protected $fillable = [
        'user_id',
        'harvest_log_id',
        'cwd_zone_id',
        'acknowledged_at',
        'audit_event_id',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'acknowledged_at' => 'datetime',
        ]);
    }
}
