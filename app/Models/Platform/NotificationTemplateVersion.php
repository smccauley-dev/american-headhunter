<?php

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;

class NotificationTemplateVersion extends Model
{
    protected $connection = 'platform';
    protected $table      = 'notification_template_versions';

    public $incrementing = false;
    protected $keyType   = 'string';
    public $timestamps   = false;

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
