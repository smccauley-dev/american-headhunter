<?php

namespace App\Models\Property;

use App\Models\BaseModel;

/**
 * Internal staff note about a property ownership-proof submission. Append-only —
 * date-time and author stamped — shown only in the admin Ownership tab, never to
 * the landowner. No soft deletes: notes are a permanent review record.
 */
class PropertyOwnershipReviewNote extends BaseModel
{
    protected $connection = 'property';
    protected $table      = 'property_ownership_review_notes';

    protected $fillable = [
        'property_id',
        'verification_id',
        'author_user_id',
        'note',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /** The staff member who wrote the note. Cross-DB (DB 1) — never an Eloquent relation. */
    public function getAuthor(): ?\App\Models\Identity\User
    {
        return $this->author_user_id
            ? app(\App\Services\Identity\UserService::class)->findById($this->author_user_id)
            : null;
    }
}
