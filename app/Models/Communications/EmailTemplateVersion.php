<?php

namespace App\Models\Communications;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailTemplateVersion extends BaseModel
{
    protected $connection = 'communications';
    protected $table      = 'email_template_versions';

    public const STATUSES = ['draft' => 'Draft', 'active' => 'Active', 'archived' => 'Archived'];

    protected $fillable = [
        'template_id',
        'version_number',
        'subject',
        'html_body',
        'text_body',
        'status',
        'notes',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'version_number' => 'integer',
        ]);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class, 'template_id');
    }
}
