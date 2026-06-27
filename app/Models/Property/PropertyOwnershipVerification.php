<?php

namespace App\Models\Property;

use App\Models\BaseModelWithSoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertyOwnershipVerification extends BaseModelWithSoftDeletes
{
    protected $connection = 'property';
    protected $table      = 'property_ownership_verifications';

    /** Who the submitter is in relation to the land. */
    public const OWNER_TYPES = [
        'individual' => 'Individual owner',
        'company'    => 'Company / entity owner',
        'manager'    => 'Manager / agent on behalf of the owner',
    ];

    /** Suggested proof documents by owner type — informational, shown to the submitter. */
    public const SUGGESTED_PROOF = [
        'individual' => [
            'Recorded land deed',
            'County tax / assessment record showing your name',
            'Land plat or boundary survey',
        ],
        'company' => [
            'Recorded land deed in the company name',
            'County tax / assessment record in the company name',
            'Entity formation document (articles / certificate)',
            'Land plat or boundary survey',
        ],
        'manager' => [
            'Signed property management agreement',
            "Written authorization from the owner",
            'County tax record + your authorization',
            'Land plat or boundary survey',
        ],
    ];

    protected $fillable = [
        'property_id',
        'submitted_by_user_id',
        'owner_type',
        'entity_name',
        'status',
        'document_ids',
        'certification_name',
        'certified_at',
        'reviewed_by_user_id',
        'reviewed_at',
        'review_notes',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'document_ids' => 'array',
            'certified_at' => 'datetime',
            'reviewed_at'  => 'datetime',
        ]);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class, 'property_id');
    }

    public function ownerTypeLabel(): string
    {
        return self::OWNER_TYPES[$this->owner_type] ?? ucfirst((string) $this->owner_type);
    }

    /** The landowner who submitted the proof. Cross-DB (DB 1) — never an Eloquent relation. */
    public function getSubmitter(): ?\App\Models\Identity\User
    {
        return $this->submitted_by_user_id
            ? app(\App\Services\Identity\UserService::class)->findById($this->submitted_by_user_id)
            : null;
    }

    /** The staff member who reviewed the proof. Cross-DB (DB 1) — never an Eloquent relation. */
    public function getReviewer(): ?\App\Models\Identity\User
    {
        return $this->reviewed_by_user_id
            ? app(\App\Services\Identity\UserService::class)->findById($this->reviewed_by_user_id)
            : null;
    }
}
