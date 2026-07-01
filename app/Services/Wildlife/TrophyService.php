<?php

namespace App\Services\Wildlife;

use App\Models\Wildlife\Trophy;
use App\Services\Audit\AuditService;
use App\Services\BaseService;
use Illuminate\Support\Collection;

/**
 * Trophy scoring for a harvest (Boone & Crockett, Pope & Young, SCI, Buckmasters).
 *
 * One score per (harvest, scoring_system) — recording the same system again
 * updates the existing row rather than duplicating it. AI-assisted scoring
 * (flag `ai_trophy_scoring`) is a Phase 6.4 job; this service is the manual /
 * official-scorer path.
 *
 * Access is delegated to HarvestService::findForUser, so a caller can only score
 * a harvest they are allowed to read (own record, standing on the lease, or
 * manages the property).
 */
class TrophyService extends BaseService
{
    public function __construct(
        private readonly HarvestService $harvests,
        private readonly AuditService $audit,
    ) {}

    /**
     * @param  array<string,mixed>  $data  scoring_system (required); gross_score,
     *                                     net_score, is_official, scored_by, scored_at, notes.
     */
    public function record(string $userId, string $harvestLogId, array $data): Trophy
    {
        // Authorization: the caller must be allowed to read this harvest (404 otherwise).
        $this->harvests->findForUser($userId, $harvestLogId);

        $trophy = Trophy::on('wildlife')
            ->where('harvest_log_id', $harvestLogId)
            ->where('scoring_system', $data['scoring_system'])
            ->whereNull('deleted_at')
            ->first();

        $attributes = [
            'gross_score' => $data['gross_score'] ?? null,
            'net_score' => $data['net_score'] ?? null,
            'is_official' => $data['is_official'] ?? false,
            'scored_by' => $data['scored_by'] ?? null,
            'scored_at' => $data['scored_at'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];

        if ($trophy) {
            $trophy->update($attributes);
        } else {
            $trophy = Trophy::create(array_merge($attributes, [
                'harvest_log_id' => $harvestLogId,
                'scoring_system' => $data['scoring_system'],
            ]));
        }

        $this->audit->log(
            eventType: 'trophy.scored',
            sourceDatabase: 'wildlife',
            tableName: 'trophies',
            recordId: $trophy->id,
            userId: $userId,
            actionSummary: "Recorded {$data['scoring_system']} score for a harvest",
        );

        return $trophy;
    }

    /** @return Collection<int,Trophy> */
    public function forHarvest(string $userId, string $harvestLogId): Collection
    {
        $this->harvests->findForUser($userId, $harvestLogId);

        return Trophy::on('wildlife')
            ->where('harvest_log_id', $harvestLogId)
            ->whereNull('deleted_at')
            ->orderByDesc('gross_score')
            ->get();
    }
}
