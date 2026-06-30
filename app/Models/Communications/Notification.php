<?php

namespace App\Models\Communications;

use App\Models\BaseModel;

/**
 * In-app notification (DB 7). Append-only: created by the system, optionally
 * stamped read_at, never updated otherwise (see the migration's RLS notes).
 * System-authored — only ah_system writes; members read their own and mark
 * them read.
 */
class Notification extends BaseModel
{
    protected $connection = 'communications';
    protected $table      = 'notifications';

    protected $fillable = [
        'user_id',
        'type',
        'channel',
        'title',
        'body',
        'action_url',
        'data',
        'read_at',
        'sent_at',
        'failed_at',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'data'      => 'array',
            'read_at'   => 'datetime',
            'sent_at'   => 'datetime',
            'failed_at' => 'datetime',
        ]);
    }
}
