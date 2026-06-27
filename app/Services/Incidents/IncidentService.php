<?php

namespace App\Services\Incidents;

use App\Models\Identity\User;
use App\Models\Incidents\IncidentAdminNote;
use App\Models\Incidents\IncidentReport;
use App\Models\Lease\Lease;
use App\Models\Property\PropertyListing;
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

    /** Valid line-item incident types (the lead column's CHECK mirrors this). */
    public const TYPES = [
        'hunting_accident', 'trespassing', 'property_damage',
        'wildlife_encounter', 'medical', 'fire', 'other',
    ];

    /** Valid severities, low → high. */
    public const SEVERITIES = ['minor', 'moderate', 'serious', 'critical'];

    /** Severity ordering, used to derive the report's worst-case lead severity. */
    private const SEVERITY_RANK = ['minor' => 1, 'moderate' => 2, 'serious' => 3, 'critical' => 4];

    /**
     * Descriptive (non-item) fields an admin/reporter may correct via updateDetails().
     * The per-item type/severity/occurred_at are edited as the `items` set (which also
     * re-derives the scalar lead columns); status/resolution flow separately.
     */
    public const EDITABLE_FIELDS = [
        'location_description',
        'description',
        'injuries_reported',
        'authorities_notified',
        'authority_report_number',
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
     * @param array<string,mixed> $data           items (list of {type, severity, occurred_at}) OR the legacy scalar
     *                                             incident_type/severity/occurred_at, plus description and the optional
     *                                             location/injury/authority fields
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

        $items = $this->normalizeItems($data);
        [$leadType, $leadSeverity, $occurredAt] = $this->deriveLead($items);

        [$listingId, $incidentNumber] = $this->nextIncidentNumber($lease->property_id);

        $report = IncidentReport::create([
            'property_id'             => $lease->property_id,
            'listing_id'              => $listingId,
            'incident_number'         => $incidentNumber,
            'lease_id'                => $lease->id,
            'reporter_user_id'        => $reporter->id,
            'incident_type'           => $leadType,
            'severity'                => $leadSeverity,
            'incident_items'          => $items,
            'parties_involved'        => $this->normalizeParties($data),
            'status'                  => self::STATUS_OPEN,
            'occurred_at'             => $occurredAt,
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
            actionSummary:  "Member filed safety incident {$report->incident_number}",
            newValues:      [
                'incident_number' => $report->incident_number,
                'incident_type'   => $report->incident_type,
                'severity'        => $report->severity,
                'item_count'      => count($items),
            ],
        );

        return $report;
    }

    /**
     * Normalise a report's line items to a clean list of {type, severity, occurred_at(ISO)}.
     * Accepts the `items` array (the multi-type shape) or the legacy single scalar
     * incident_type/severity/occurred_at. Incomplete rows are dropped; at least one
     * complete item is required.
     *
     * @param array<string,mixed> $data
     * @return array<int,array{type:string,severity:string,occurred_at:string}>
     */
    private function normalizeItems(array $data): array
    {
        $raw = $data['items'] ?? null;

        if (! is_array($raw) || $raw === []) {
            // Legacy single-item shape.
            if (! isset($data['incident_type'], $data['severity'], $data['occurred_at'])) {
                throw new \InvalidArgumentException('An incident needs at least one line item (type, severity, when).');
            }
            $raw = [[
                'type'        => $data['incident_type'],
                'severity'    => $data['severity'],
                'occurred_at' => $data['occurred_at'],
            ]];
        }

        $items = [];
        foreach ($raw as $item) {
            $type       = $item['type'] ?? null;
            $severity   = $item['severity'] ?? null;
            $occurredAt = $item['occurred_at'] ?? null;
            if (! $type || ! $severity || ! $occurredAt) {
                continue;
            }
            $items[] = [
                'type'        => (string) $type,
                'severity'    => (string) $severity,
                'occurred_at' => \Illuminate\Support\Carbon::parse($occurredAt)->toIso8601String(),
            ];
        }

        if ($items === []) {
            throw new \InvalidArgumentException('An incident needs at least one line item (type, severity, when).');
        }

        return $items;
    }

    /**
     * Normalise the involved-parties list to a clean set of { full_name, is_minor }.
     * Rows without a name are dropped. is_minor is a plain "under 18" flag — no date
     * of birth is captured, so no protected personal data is stored.
     *
     * @param array<string,mixed> $data
     * @return array<int,array{full_name:string,is_minor:bool}>
     */
    private function normalizeParties(array $data): array
    {
        $raw = $data['parties'] ?? null;
        if (! is_array($raw)) {
            return [];
        }

        $parties = [];
        foreach ($raw as $party) {
            $name = trim((string) ($party['full_name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $parties[] = [
                'full_name' => $name,
                'is_minor'  => (bool) ($party['is_minor'] ?? false),
            ];
        }

        return $parties;
    }

    /**
     * Whether two involved-parties lists differ (by name + minor flag, in order).
     *
     * @param array<int,array<string,mixed>> $a
     * @param array<int,array<string,mixed>> $b
     */
    private function partiesDiffer(array $a, array $b): bool
    {
        $key = static fn (array $parties): array => array_map(
            static fn (array $p): string => trim((string) ($p['full_name'] ?? '')).'|'.((bool) ($p['is_minor'] ?? false) ? '1' : '0'),
            $parties,
        );

        return $key($a) !== $key($b);
    }

    /**
     * Derive the scalar "lead" columns from a report's line items: lead type = the
     * first item; severity = the worst across all items; occurred_at = the earliest.
     * This keeps the indexed/badged/sorted scalar columns meaningful for a multi-type
     * report.
     *
     * @param array<int,array{type:string,severity:string,occurred_at:string}> $items
     * @return array{0:string,1:string,2:\Illuminate\Support\Carbon}
     */
    private function deriveLead(array $items): array
    {
        $leadType = $items[0]['type'];

        $worst = $items[0]['severity'];
        foreach ($items as $item) {
            if ((self::SEVERITY_RANK[$item['severity']] ?? 0) > (self::SEVERITY_RANK[$worst] ?? 0)) {
                $worst = $item['severity'];
            }
        }

        $earliest = null;
        foreach ($items as $item) {
            $when = \Illuminate\Support\Carbon::parse($item['occurred_at']);
            if ($earliest === null || $when->lt($earliest)) {
                $earliest = $when;
            }
        }

        return [$leadType, $worst, $earliest];
    }

    /**
     * Whether two line-item lists differ at the resolution the picker captures
     * (type, severity, minute-precision occurred_at) — so an untouched edit with
     * stray seconds isn't logged as a change.
     *
     * @param array<int,array<string,mixed>> $a
     * @param array<int,array<string,mixed>> $b
     */
    private function itemsDiffer(array $a, array $b): bool
    {
        $key = static fn (array $items): array => array_map(
            static fn (array $i): string => $i['type'].'|'.$i['severity'].'|'
                .\Illuminate\Support\Carbon::parse($i['occurred_at'])->format('Y-m-d H:i'),
            $items,
        );

        return $key($a) !== $key($b);
    }

    /**
     * Allocate the next case number for a property's listing:
     * IR-<first 8 chars of the listing id, uppercased>-<NN>, where NN is a per-listing
     * sequence. The listing is resolved from property_id (cross-DB, DB 2); when a
     * property has no listing the property id is used as the scope so a number is still
     * issued. The sequence counts every prior incident for that scope (including
     * soft-deleted) so numbers are never reused; the unique index is the hard guard.
     *
     * @return array{0: ?string, 1: string} [listingId, incidentNumber]
     */
    private function nextIncidentNumber(string $propertyId): array
    {
        $listingId = PropertyListing::on('property')
            ->where('property_id', $propertyId)
            ->orderBy('created_at')
            ->value('id');

        $scopeId = $listingId ?? $propertyId;

        $priorCount = IncidentReport::withTrashed()
            ->where($listingId ? 'listing_id' : 'property_id', $scopeId)
            ->count();

        $number = sprintf('IR-%s-%02d', strtoupper(substr((string) $scopeId, 0, 8)), $priorCount + 1);

        return [$listingId, $number];
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
        $previousStatus = $report->status;

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
            changedFields:  ['status'],
            oldValues:      ['status' => $previousStatus],
            newValues:      ['status' => $status],
        );

        return $report;
    }

    /**
     * Correct an incident's descriptive fields (type, severity, when/where, the
     * narrative, and the injury/authority flags). Only the keys present in $data
     * and listed in EDITABLE_FIELDS are touched; the status workflow and resolution
     * notes are handled by updateStatus(). The change is recorded as a field-level
     * before/after diff in the audit log so every edit is attributable to an actor.
     *
     * Evidence is append-only: $addEvidenceDocIds may add photos to an existing report
     * but no path here (or anywhere) removes one — once uploaded, a photo is permanent.
     *
     * @param array<string,mixed> $data
     * @param array<int,string>   $addEvidenceDocIds  DB 11 document ids to append (never remove)
     */
    public function updateDetails(
        string $incidentId,
        array $data,
        ?string $actorUserId = null,
        array $addEvidenceDocIds = [],
    ): IncidentReport {
        $report = IncidentReport::findOrFail($incidentId);

        $old = [];
        $new = [];

        // Line items (type/severity/when) are edited as a set. When provided, replace
        // the list and re-derive the scalar lead columns (type/severity/occurred_at).
        if (array_key_exists('items', $data) && is_array($data['items'])) {
            $incomingItems = $this->normalizeItems($data);
            if ($this->itemsDiffer($report->incident_items ?? [], $incomingItems)) {
                [$leadType, $leadSeverity, $occurredAt] = $this->deriveLead($incomingItems);
                $old['incident_items'] = $report->incident_items ?? [];
                $report->incident_items = $incomingItems;
                $report->incident_type  = $leadType;
                $report->severity       = $leadSeverity;
                $report->occurred_at    = $occurredAt;
                $new['incident_items']  = $incomingItems;
            }
        }

        // Involved parties (name + "under 18" flag) are edited as a set, like items.
        // Audited as a count only — minors' names are never written to the audit log.
        $partiesChanged = false;
        $partyCounts    = [];
        if (array_key_exists('parties', $data)) {
            $incomingParties = $this->normalizeParties($data);
            $currentParties  = $report->parties_involved ?? [];
            if ($this->partiesDiffer($currentParties, $incomingParties)) {
                $partiesChanged = true;
                $partyCounts = [
                    'old' => ['party_count' => count($currentParties), 'minor_count' => $this->minorCount($currentParties)],
                    'new' => ['party_count' => count($incomingParties), 'minor_count' => $this->minorCount($incomingParties)],
                ];
                $report->parties_involved = $incomingParties;
            }
        }

        foreach (self::EDITABLE_FIELDS as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $incoming = $data[$field];
            if (in_array($field, ['injuries_reported', 'authorities_notified'], true)) {
                $incoming = (bool) $incoming;
            } elseif ($incoming === '') {
                $incoming = null;
            }

            $current = $report->{$field};
            if ((string) $current === (string) $incoming) {
                continue;
            }

            $old[$field] = $current;
            $report->{$field} = $incoming;
            $new[$field] = $incoming;
        }

        // Append-only photo evidence: keep every existing id, add the genuinely new ones.
        $existingEvidence = $report->evidence_document_ids ?? [];
        $appended = array_values(array_diff(array_values(array_filter($addEvidenceDocIds)), $existingEvidence));

        if (! $new && ! $appended && ! $partiesChanged) {
            return $report;
        }

        if ($appended) {
            $report->evidence_document_ids = array_values(array_merge($existingEvidence, $appended));
        }

        $report->save();

        if ($appended) {
            $this->documents->attachDocuments($appended);
        }

        if ($new) {
            $this->audit->log(
                eventType:      'incident_report.updated',
                sourceDatabase: 'ah_incidents',
                tableName:      'incident_reports',
                recordId:       $report->id,
                userId:         $actorUserId,
                actionSummary:  'Incident report details edited',
                changedFields:  array_keys($new),
                oldValues:      $old,
                newValues:      $new,
            );
        }

        if ($appended) {
            $this->audit->log(
                eventType:      'incident_report.evidence_added',
                sourceDatabase: 'ah_incidents',
                tableName:      'incident_reports',
                recordId:       $report->id,
                userId:         $actorUserId,
                actionSummary:  count($appended) . ' photo(s) added to incident report',
                changedFields:  ['evidence_document_ids'],
                oldValues:      ['photo_count' => count($existingEvidence)],
                newValues:      ['photo_count' => count($report->evidence_document_ids)],
            );
        }

        if ($partiesChanged) {
            $this->audit->log(
                eventType:      'incident_report.parties_updated',
                sourceDatabase: 'ah_incidents',
                tableName:      'incident_reports',
                recordId:       $report->id,
                userId:         $actorUserId,
                actionSummary:  'Involved parties updated',
                changedFields:  ['parties_involved'],
                oldValues:      $partyCounts['old'],
                newValues:      $partyCounts['new'],
            );
        }

        return $report;
    }

    /** Count of parties flagged as minors (under 18) in a parties list. */
    private function minorCount(array $parties): int
    {
        return count(array_filter($parties, static fn (array $p): bool => (bool) ($p['is_minor'] ?? false)));
    }

    /**
     * Append an admin-only investigation note to an incident — one timestamped line
     * item in its internal log. Visible to staff only (never the reporter); append-only
     * (no edit/delete). The author is recorded for accountability and the action is
     * audited.
     *
     * @throws \InvalidArgumentException when the note body is empty
     */
    public function addAdminNote(string $incidentId, string $note, string $actorUserId): IncidentAdminNote
    {
        $report = IncidentReport::findOrFail($incidentId);

        $note = trim($note);
        if ($note === '') {
            throw new \InvalidArgumentException('An investigation note cannot be empty.');
        }

        $record = IncidentAdminNote::create([
            'incident_report_id' => $report->id,
            'author_user_id'     => $actorUserId,
            'note'               => $note,
        ]);

        $this->audit->log(
            eventType:      'incident_report.note_added',
            sourceDatabase: 'ah_incidents',
            tableName:      'incident_admin_notes',
            recordId:       $record->id,
            userId:         $actorUserId,
            actionSummary:  "Investigation note added to incident {$report->incident_number}",
        );

        return $record->refresh();
    }

    /**
     * An incident's admin-only investigation notes, newest first (for the admin UI).
     *
     * @return \Illuminate\Support\Collection<int,IncidentAdminNote>
     */
    public function adminNotes(string $incidentId): \Illuminate\Support\Collection
    {
        return IncidentAdminNote::where('incident_report_id', $incidentId)
            ->orderByDesc('created_at')
            ->get();
    }
}
