<?php

namespace App\Models\Incidents;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Incident report (DB 10) — a safety incident on or related to a property.
 *
 * System-authored: written only by the trusted ah_system path (the db.system member
 * route that files the report, and the Filament admin panel that triages it); read
 * under ah_runtime scoped by RLS to the reporter and staff. All cross-DB references
 * (property, lease, reporter, evidence documents) are bare UUID columns resolved in
 * the service layer — never Eloquent relationships.
 */
class IncidentReport extends BaseModel
{
    use SoftDeletes;

    protected $connection = 'incidents';
    protected $table      = 'incident_reports';

    protected $fillable = [
        'property_id',
        'listing_id',
        'incident_number',
        'lease_id',
        'reporter_user_id',
        'incident_type',
        'severity',
        'incident_items',
        'parties_involved',
        'status',
        'occurred_at',
        'location_description',
        'description',
        'injuries_reported',
        'authorities_notified',
        'authority_report_number',
        'evidence_document_ids',
        'resolved_at',
        'resolution_notes',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'occurred_at'           => 'datetime',
            'injuries_reported'     => 'boolean',
            'authorities_notified'  => 'boolean',
            'evidence_document_ids' => 'array',
            'incident_items'        => 'array',
            'parties_involved'      => 'array',
            'resolved_at'           => 'datetime',
            'deleted_at'            => 'datetime',
        ]);
    }

    /** The member who filed the report. Cross-DB (DB 1) — service layer, not Eloquent. */
    public function getReporter(): ?\App\Models\Identity\User
    {
        return $this->reporter_user_id
            ? app(\App\Services\Identity\UserService::class)->findById($this->reporter_user_id)
            : null;
    }

    /** The property the incident occurred on. Cross-DB (DB 2). */
    public function getProperty(): ?\App\Models\Property\Property
    {
        return $this->property_id
            ? app(\App\Services\Property\PropertyService::class)->find($this->property_id)
            : null;
    }

    /** A human-readable label for the linked lease, if any. Cross-DB (DB 3). */
    public function leaseLabel(): ?string
    {
        if (! $this->lease_id) {
            return null;
        }

        $lease = app(\App\Services\Lease\LeaseService::class)->find($this->lease_id);

        return $lease?->getProperty()?->title ?? ($lease ? 'Lease' : null);
    }
}
