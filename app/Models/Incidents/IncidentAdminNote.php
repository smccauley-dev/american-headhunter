<?php

namespace App\Models\Incidents;

use App\Models\BaseModel;

/**
 * Admin-only investigation note (DB 10) — one timestamped line item in an incident's
 * internal log. System-authored (written only by the Filament admin panel) and
 * readable under ah_runtime by staff/super_admin only; the reporter can never see it.
 * Append-only — no updated_at/deleted_at and no edit/delete path. The author is a
 * bare cross-DB (DB 1) UUID resolved in the service/resource layer, not via Eloquent.
 */
class IncidentAdminNote extends BaseModel
{
    protected $connection = 'incidents';
    protected $table      = 'incident_admin_notes';

    protected $fillable = [
        'incident_report_id',
        'author_user_id',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
