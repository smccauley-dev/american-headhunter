<?php

namespace App\Services\Lease;

use App\Models\Documents\EsignatureRequest;
use App\Models\Documents\EsignatureSigner;
use App\Models\Lease\Lease;
use App\Models\Lease\LeaseHunter;
use App\Models\Lease\SignatureEvent;
use App\Services\Audit\AuditService;
use App\Services\BaseService;

class EsignatureService extends BaseService
{
    public function __construct(
        private readonly LeaseService $leaseService,
        private readonly AuditService $auditService,
    ) {}

    // ── Read ──────────────────────────────────────────────────────────────────

    public function getRequestForLease(string $leaseId): ?EsignatureRequest
    {
        return EsignatureRequest::where('lease_id', $leaseId)
            ->latest('requested_at')
            ->first();
    }

    public function signerForUser(string $requestId, string $userId): ?EsignatureSigner
    {
        return EsignatureSigner::where('request_id', $requestId)
            ->where('user_id', $userId)
            ->first();
    }

    // ── Writes ────────────────────────────────────────────────────────────────

    /**
     * Create an in-platform signing request for a lease.
     * Lessor signs first (order 1), lessee second (order 2).
     *
     * @param  array{user_id: string, name: string, email: string}  $lessorInfo
     * @param  array{user_id: string, name: string, email: string}  $lesseeInfo
     */
    public function createRequest(
        Lease  $lease,
        string $requestedByUserId,
        array  $lessorInfo,
        array  $lesseeInfo,
    ): EsignatureRequest {
        $year = $lease->start_date?->format('Y') ?? now()->year;

        $request = EsignatureRequest::create([
            'lease_id'          => $lease->id,
            'requester_user_id' => $requestedByUserId,
            'provider'          => 'in_platform',
            'status'            => 'out_for_signature',
            'subject'           => "Hunting Lease Agreement — {$year}",
            'requested_at'      => now(),
        ]);

        EsignatureSigner::create([
            'request_id' => $request->id,
            'user_id'    => $lessorInfo['user_id'],
            'email'      => $lessorInfo['email'],
            'name'       => $lessorInfo['name'],
            'order_num'  => 1,
            'status'     => 'pending',
        ]);

        EsignatureSigner::create([
            'request_id' => $request->id,
            'user_id'    => $lesseeInfo['user_id'],
            'email'      => $lesseeInfo['email'],
            'name'       => $lesseeInfo['name'],
            'order_num'  => 2,
            'status'     => 'pending',
        ]);

        // Permanent audit trail in DB 3 — never deleted
        SignatureEvent::create([
            'lease_id'    => $lease->id,
            'user_id'     => $requestedByUserId,
            'provider'    => 'in_platform',
            'event_type'  => 'sent',
            'occurred_at' => now(),
        ]);

        return $request;
    }

    /**
     * Record an in-platform signature for a user.
     * Returns true if this was the final signature and the lease was activated.
     */
    public function recordSignature(
        string $requestId,
        string $userId,
        string $ipAddress = '',
        string $userAgent = '',
    ): bool {
        $signer = EsignatureSigner::where('request_id', $requestId)
            ->where('user_id', $userId)
            ->first();

        if (! $signer || $signer->status === 'signed') {
            return false;
        }

        $signer->status    = 'signed';
        $signer->signed_at = now();
        $signer->save();

        $request = EsignatureRequest::findOrFail($requestId);

        // Permanent event in DB 3
        SignatureEvent::create([
            'lease_id'    => $request->lease_id,
            'user_id'     => $userId,
            'provider'    => 'in_platform',
            'event_type'  => 'signed',
            'occurred_at' => now(),
            'ip_address'  => $ipAddress ?: null,
            'user_agent'  => $userAgent ?: null,
        ]);

        return $this->activateIfComplete($request);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function activateIfComplete(EsignatureRequest $request): bool
    {
        if (! $request->allSigned()) {
            return false;
        }

        $request->status       = 'completed';
        $request->completed_at = now();
        $request->save();

        // Permanent completion event in DB 3
        SignatureEvent::create([
            'lease_id'    => $request->lease_id,
            'user_id'     => $request->requester_user_id,
            'provider'    => 'in_platform',
            'event_type'  => 'completed',
            'occurred_at' => now(),
        ]);

        // Activate the lease record
        $this->leaseService->activate($request->lease_id);

        // Approve the primary lessee in lease_hunters
        LeaseHunter::where('lease_id', $request->lease_id)
            ->where('role', 'primary')
            ->update(['is_approved' => true, 'approved_at' => now()]);

        try {
            $this->auditService->log(
                eventType:      'lease.activated',
                sourceDatabase: 'ah_lease',
                tableName:      'leases',
                recordId:       $request->lease_id,
                actionSummary:  'Lease activated after all in-platform signatures collected',
            );
        } catch (\Throwable) {}

        return true;
    }
}
