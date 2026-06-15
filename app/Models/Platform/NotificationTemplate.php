<?php

namespace App\Models\Platform;

use App\Models\BaseModel;

class NotificationTemplate extends BaseModel
{
    protected $connection = 'platform';
    protected $table      = 'notification_templates';

    protected $fillable = [
        'slug',
        'channel',
        'description',
        'variable_schema',
    ];

    protected function casts(): array
    {
        return [
            'variable_schema' => 'array',
            'created_at'      => 'datetime',
            'updated_at'      => 'datetime',
        ];
    }

    public function versions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(NotificationTemplateVersion::class, 'template_id');
    }

    public function productionVersion(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(NotificationTemplateVersion::class, 'template_id')
                    ->where('status', 'production')
                    ->orderByDesc('version_number');
    }
}
