<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Wildlife\CwdZone;
use App\Services\Wildlife\CwdService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mobile CWD reference API. Published state regulation, not tenant data — any
 * authenticated member (hunter:read) may fetch a state's zones to cache offline
 * and drive the harvest-time acknowledgment prompt. The authoritative gate still
 * runs server-side at harvest sync (HarvestService), never on this cache.
 */
class CwdController extends Controller
{
    public function __construct(
        private readonly CwdService $cwd,
    ) {}

    /** CWD zones for a state (?state=XX). */
    public function zones(Request $request): JsonResponse
    {
        $data = $request->validate([
            'state' => ['required', 'string', 'size:2'],
        ]);

        $rows = $this->cwd->zonesForState($data['state'])
            ->map(fn (CwdZone $z) => [
                'id' => $z->id,
                'state_code' => $z->state_code,
                'zone_name' => $z->zone_name,
                'zone_type' => $z->zone_type,
                'regulations' => $z->regulations,
                'effective_date' => $z->effective_date?->toDateString(),
                'requires_acknowledgment' => $z->requiresAcknowledgment(),
            ])
            ->all();

        return response()->json([
            'state' => strtoupper($data['state']),
            'zones' => $rows,
        ]);
    }
}
