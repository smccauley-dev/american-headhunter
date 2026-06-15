<?php

namespace App\Models\Platform;

use App\Models\BaseModel;

class NotificationTemplateVersion extends BaseModel
{
    protected $connection = 'platform';
    protected $table      = 'notification_template_versions';

    protected $fillable = [
        'template_id',
        'version_number',
        'status',
        'subject',
        'html_body',
        'text_body',
        'promoted_at',
        'promoted_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'promoted_at'    => 'datetime',
            'created_at'     => 'datetime',
            'updated_at'     => 'datetime',
        ];
    }

    public function template(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(NotificationTemplate::class, 'template_id');
    }
}
