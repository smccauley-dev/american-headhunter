<?php

namespace App\Models\Lease;

use App\Models\BaseModelWithSoftDeletes;
use App\Models\Traits\HasEncryptedFields;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LeaseApplication extends BaseModelWithSoftDeletes
{
    use HasEncryptedFields;

    protected $connection = 'lease';
    protected $table      = 'lease_applications';

    // -- encrypted via pgp_sym_encrypt (Key C)
    protected array $encryptedFields = ['message'];

    protected $fillable = [
        'listing_id',
        'applicant_user_id',
        'application_type',
        'status',
        'message',
        'admin_notes',
        'desired_hunters',
        'proposed_start',
        'proposed_end',
        'reviewed_by_user_id',
        'reviewed_at',
        'rejection_reason',
        'booking_fee_deadline',
        'closed_reason',
        // Snapshot fields — populated at submit time; survive listing archival
        'property_id_snapshot',
        'property_title_snapshot',
        'property_slug_snapshot',
        'property_location_snapshot',
        'listing_season_start_snap',
        'listing_season_end_snap',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'proposed_start'          => 'date',
            'proposed_end'            => 'date',
            'reviewed_at'             => 'datetime',
            'booking_fee_deadline'    => 'datetime',
            'desired_hunters'         => 'integer',
            'listing_season_start_snap' => 'date',
            'listing_season_end_snap'   => 'date',
        ]);
    }

    // ── Relationships within DB 3 ─────────────────────────────────────────────

    public function lease(): HasOne
    {
        return $this->hasOne(Lease::class, 'application_id');
    }

    // ── Cross-DB getters ──────────────────────────────────────────────────────

    public function getListing(): ?\App\Models\Property\PropertyListing
    {
        return app(\App\Services\Property\PropertyService::class)->findListing($this->listing_id);
    }

    public function getApplicant(): ?\App\Models\Identity\User
    {
        return app(\App\Services\Identity\UserService::class)->findById($this->applicant_user_id);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopePending($query)
    {
        return $query->whereIn('status', ['pending', 'under_review']);
    }

    public function scopeForListing($query, string $listingId)
    {
        return $query->where('listing_id', $listingId);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isPending(): bool
    {
        return in_array($this->status, ['pending', 'under_review']);
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    /**
     * Whether this application is approved and still inside its 24-hour booking-fee
     * window — i.e. the applicant may still pay the (held) booking fee to claim the
     * spot. False once the deadline lapses or the application leaves 'approved'.
     */
    public function bookingWindowOpen(): bool
    {
        return $this->status === 'approved'
            && $this->booking_fee_deadline !== null
            && $this->booking_fee_deadline->isFuture();
    }
}
