<?php

namespace App\Models\Communications;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class EmailTemplate extends BaseModel
{
    protected $connection = 'communications';
    protected $table      = 'email_templates';

    protected $fillable = [
        'template_key',
        'name',
        'category',
        'owner_type',
        'owner_user_id',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'deleted_at' => 'datetime',
        ]);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(EmailTemplateVersion::class, 'template_id')
                    ->orderByDesc('version_number');
    }

    public function activeVersion(): HasOne
    {
        return $this->hasOne(EmailTemplateVersion::class, 'template_id')
                    ->where('status', 'active');
    }

    public function isSystem(): bool
    {
        return $this->category === 'system';
    }
}
