<?php

namespace App\Services\Incidents;

use App\Models\Identity\User;
use App\Models\Incidents\IncidentReport;
use App\Models\Lease\Lease;
use App\Services\Audit\AuditService;
use App\Services\BaseService;
use App\Services\Documents\DocumentService;

/**
 * Incident reports (DB 10) — safety-incident intake and triage. A member files an
 * incident tied to a lease/property with optional photo evidence; the safety team
 * works it through an open → investigating → resolved → closed workflow. All cross-DB
 * data (lease, property, reporter, evidence) is assembled in the service layer.
 */
class IncidentService extends BaseService
{
    /** Triage statuses, in order. */
    public const STATUS_OPEN          = 'open';
    public const STATUS_INVESTIGATING = 'investigating';
    public const STATUS_RESOLVED      = 'resolved';
    public const STATUS_CLOSED        = 'closed';

    /** The valid status transitions for updateStatus(). */
    private const TRANSITIONS = [
        self::STATUS_OPEN          => [self::STATUS_INVESTIGATING, self::STATUS_RESOLVED, self::STATUS_CLOSED],
        self::STATUS_INVESTIGATING => [self::STATUS_RESOLVED, self::STATUS_CLOSED],
        self::STATUS_RESOLVED      => [self::STATUS_CLOSED, self::STATUS_INVESTIGATING],
        self::STATUS_CLOSED        => [],
    ];

    public function __construct(
        private readonly DocumentService $documents,
        private readonly AuditService    $audit,
    ) {}

    /** A lease's incident reports, newest occurrence first (for member-portal display). */
    public function forLease(string $leaseId): \Illuminate\Support\Collection
    {
        return IncidentReport::where('lease_id', $leaseId)
            ->orderByDesc('occurred_at')
            ->get();
    }

    /** A property's incident reports, newest occurrence first. */
    public function forProperty(string $propertyId): \Illuminate\Support\Collection
    {
        return IncidentReport::where('property_id', $propertyId)
            ->orderByDesc('occurred_at')
            ->get();
    }

    /**
     * File a member's incident report against a lease, with optional photo evidence.
     * The reporter must be a party to the lease (lessee or lessor); property_id is
     * derived from the lease.
     *
     * @param array<string,mixed> $data           incident_type, severity, occurred_at, description, and the optional location/injury/authority fields
     * @param array<int,string>   $evidenceDocIds  DB 11 document ids (photo proof)
     *
     * @throws \RuntimeException when the reporter is not a party to the lease
     */
    public function file(
        Lease $lease,
        User $reporter,
        array $data,
        array $evidenceDocIds = [],
    ): IncidentReport {
        $isParty = in_array((string) $reporter->id, [
            (string) $lease->lessee_user_id,
            (string) $lease->lessor_user_id,
        ], true);
        if (! $isParty) {
            throw new \RuntimeException('Only a party to the lease may report an incident on it.');
        }

        $evidenceDocIds = array_values(array_filter($evidenceDocIds));

        $report = IncidentReport::create([
            'property_id'             => $lease->property_id,
            'lease_id'                => $lease->id,
            'reporter_user_id'        => $reporter->id,
            'incident_type'           => $data['incident_type'],
            'severity'                => $data['severity'],
            'status'                  => self::STATUS_OPEN,
            'occurred_at'             => $data['occurred_at'],
            'location_description'    => $data['location_description'] ?? null,
            'description'             => $data['description'],
            'injuries_reported'       => (bool) ($data['injuries_reported'] ?? false),
            'authorities_notified'    => (bool) ($data['authorities_notified'] ?? false),
            'authority_report_number' => $data['authority_report_number'] ?? null,
            'evidence_document_ids'   => $evidenceDocIds,
        ]);

        if ($evidenceDocIds) {
            $this->documents->attachDocuments($evidenceDocIds);
        }

        $this->audit->log(
            eventType:      'incident_report.filed',
            sourceDatabase: 'ah_incidents',
            tableName:      'incident_reports',
            recordId:       $report->id,
            userId:         $reporter->id,
            actionSummary:  'Member filed a safety incident report',
            newValues:      ['incident_type' => $report->incident_type, 'severity' => $report->severity],
        );

        return $report;
    }

    /**
     * Advance an incident through its triage workflow. Resolving or closing captures
     * resolution notes and stamps resolved_at; reopening to investigating clears it.
     * Optionally records the authority report number captured during triage.
     *
     * @param array<string,mixed> $extra Optional: resolution_notes, authority_report_number, authorities_notified
     *
     * @throws \InvalidArgumentException on an unknown or disallowed transition
     */
    public function updateStatus(
        string $incidentId,
        string $status,
        ?string $actorUserId = null,
        array $extra = [],
    ): IncidentReport {
        $report = IncidentReport::findOrFail($incidentId);

        if (! array_key_exists($status, self::TRANSITIONS)) {
            throw new \InvalidArgumentException("Unknown incident status: {$status}.");
        }
        if ($status !== $report->status && ! in_array($status, self::TRANSITIONS[$report->status], true)) {
            throw new \InvalidArgumentException("Cannot move incident from {$report->status} to {$status}.");
        }

        if (array_key_exists('resolution_notes', $extra)) {
            $report->resolution_notes = $extra['resolution_notes'];
        }
        if (array_key_exists('authority_report_number', $extra)) {
            $report->authority_report_number = $extra['authority_report_number'];
        }
        if (array_key_exists('authorities_notified', $extra)) {
            $report->authorities_notified = (bool) $extra['authorities_notified'];
        }

        $report->resolved_at = in_array($status, [self::STATUS_RESOLVED, self::STATUS_CLOSED], true)
            ? ($report->resolved_at ?? now())
            : null;
        $report->status = $status;
        $report->save();

        $this->audit->log(
            eventType:      'incident_report.status_changed',
            sourceDatabase: 'ah_incidents',
            tableName:      'incident_reports',
            recordId:       $report->id,
            userId:         $actorUserId,
            actionSummary:  "Incident report marked {$status}",
            newValues:      ['status' => $status],
        );

        return $report;
    }
}
