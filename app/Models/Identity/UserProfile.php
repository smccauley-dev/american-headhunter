<?php

namespace App\Models\Identity;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserProfile extends BaseModel
{
    protected $connection = 'identity';
    protected $table      = 'user_profiles';

    protected $fillable = [
        'user_id',
        'first_name',
        'last_name',
        'display_name',
        'avatar_document_id',
        'bio',
        'state_code',
        'zip_code',
        'date_of_birth',
        'gender',
        'veteran_branch',
        'veteran_service_start',
        'veteran_service_end',
        'veteran_is_active',
        'veteran_last_rank',
        'veteran_bio',
        'first_responder_type',
        'first_responder_service_start',
        'first_responder_service_end',
        'first_responder_is_active',
        'first_responder_last_rank',
        'first_responder_bio',
        'notification_preferences',
        'hunting_profile',
        'social_links',
        'profile_visibility',
        'gear_profile',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'date_of_birth'            => 'date',
            'notification_preferences' => 'array',
            'hunting_profile'          => 'array',
            'social_links'             => 'array',
            'profile_visibility'       => 'array',
            'gear_profile'             => 'array',
        ]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }
}
