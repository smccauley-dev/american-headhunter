<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Documents\EsignatureRequest;
use App\Models\Documents\EsignatureSigner;
use App\Models\Lease\Lease;
use App\Services\Lease\DropboxSignService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class LeaseSigningController extends Controller
{
    /**
     * GET /api/v1/leases
     * List active + pending-signature leases for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $leases = Lease::on('lease')
            ->where(function ($q) use ($userId) {
                $q->where('lessee_user_id', $userId)
                  ->orWhere('lessor_user_id', $userId);
            })
            ->whereIn('status', ['pending_signatures', 'active', 'expired'])
            ->orderByDesc('created_at')
            ->get(['id', 'status', 'start_date', 'end_date', 'total_price', 'lessee_user_id', 'lessor_user_id', 'created_at']);

        return response()->json([
            'data' => $leases->map(fn ($lease) => [
                'id'         => $lease->id,
                'status'     => $lease->status,
                'start_date' => $lease->start_date?->toDateString(),
                'end_date'   => $lease->end_date?->toDateString(),
                'total_price' => $lease->total_price,
                'role'       => $lease->lessee_user_id === $userId ? 'lessee' : 'lessor',
            ]),
        ]);
    }

    /**
     * GET /api/v1/leases/{id}
     * Lease detail with signing status for the authenticated user.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $userId = $request->user()->id;
        $lease  = $this->authorizedLease($id, $userId);

        if (! $lease) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $esigRequest = EsignatureRequest::where('lease_id', $lease->id)
            ->latest('requested_at')
            ->first();

        $signers = $esigRequest
            ? $esigRequest->signers()->orderBy('order_num')->get(['user_id', 'name', 'email', 'status', 'signed_at', 'order_num'])
            : collect();

        $mySigner = $esigRequest
            ? EsignatureSigner::where('request_id', $esigRequest->id)
                ->where('user_id', $userId)
                ->first()
            : null;

        return response()->json([
            'data' => [
                'id'              => $lease->id,
                'status'          => $lease->status,
                'start_date'      => $lease->start_date?->toDateString(),
                'end_date'        => $lease->end_date?->toDateString(),
                'total_price'     => $lease->total_price,
                'role'            => $lease->lessee_user_id === $userId ? 'lessee' : 'lessor',
                'signing_provider' => $esigRequest?->provider,
                'signing_status'  => $esigRequest?->status,
                'my_signature'    => $mySigner ? [
                    'status'    => $mySigner->status,
                    'signed_at' => $mySigner->signed_at?->toIso8601String(),
                ] : null,
                'signers' => $signers->map(fn ($s) => [
                    'name'      => $s->name,
                    'order'     => $s->order_num,
                    'status'    => $s->status,
                    'signed_at' => $s->signed_at?->toIso8601String(),
                ]),
            ],
        ]);
    }

    /**
     * GET /api/v1/leases/{id}/signing-url
     * Returns an embedded Dropbox Sign URL for the authenticated user to sign.
     * Only works when the lease uses the dropbox_sign provider.
     */
    public function signingUrl(Request $request, string $id, DropboxSignService $dropboxSign): JsonResponse
    {
        $userId = $request->user()->id;
        $lease  = $this->authorizedLease($id, $userId);

        if (! $lease) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $esigRequest = EsignatureRequest::where('lease_id', $lease->id)
            ->where('provider', 'dropbox_sign')
            ->where('status', 'out_for_signature')
            ->latest('requested_at')
            ->first();

        if (! $esigRequest) {
            return response()->json(['error' => 'No active Dropbox Sign request for this lease'], 422);
        }

        $signer = EsignatureSigner::where('request_id', $esigRequest->id)
            ->where('user_id', $userId)
            ->where('status', 'pending')
            ->first();

        if (! $signer || ! $signer->provider_signer_id) {
            return response()->json(['error' => 'No pending signature for this user'], 422);
        }

        try {
            $signUrl = $dropboxSign->getEmbeddedSigningUrl($signer->provider_signer_id);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Failed to retrieve signing URL'], 500);
        }

        return response()->json([
            'data' => [
                'signing_url' => $signUrl,
                'expires_in'  => 3600,
            ],
        ]);
    }

    /**
     * GET /api/v1/leases/{id}/signature-status
     * Lightweight poll endpoint — returns signing status without full lease detail.
     */
    public function signatureStatus(Request $request, string $id): JsonResponse
    {
        $userId = $request->user()->id;
        $lease  = $this->authorizedLease($id, $userId);

        if (! $lease) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $esigRequest = EsignatureRequest::where('lease_id', $lease->id)
            ->latest('requested_at')
            ->first();

        $mySigner = $esigRequest
            ? EsignatureSigner::where('request_id', $esigRequest->id)
                ->where('user_id', $userId)
                ->first()
            : null;

        return response()->json([
            'data' => [
                'lease_status'    => $lease->status,
                'signing_status'  => $esigRequest?->status,
                'my_status'       => $mySigner?->status,
                'completed_at'    => $esigRequest?->completed_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * GET /api/v1/leases/{id}/contract
     * Returns a short-lived download URL for the signed contract PDF.
     */
    public function contract(Request $request, string $id): JsonResponse
    {
        $userId = $request->user()->id;
        $lease  = $this->authorizedLease($id, $userId);

        if (! $lease) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $esigRequest = EsignatureRequest::where('lease_id', $lease->id)
            ->where('status', 'completed')
            ->whereNotNull('signed_document_id')
            ->latest('completed_at')
            ->first();

        if (! $esigRequest || ! $esigRequest->signed_document_id) {
            return response()->json(['error' => 'No signed contract available yet'], 404);
        }

        $disk = config('filesystems.defaults.documents', 'local');
        $doc  = \App\Models\Documents\Document::find($esigRequest->signed_document_id);

        if (! $doc) {
            return response()->json(['error' => 'Document record not found'], 404);
        }

        // For local dev, return an inline data URI; for production, return a signed URL
        $url = $disk === 'local'
            ? route('api.leases.contract.download', ['id' => $id])
            : Storage::disk($disk)->temporaryUrl($doc->storage_key, now()->addMinutes(15));

        return response()->json([
            'data' => [
                'download_url' => $url,
                'expires_in'   => 900,
                'filename'     => $doc->original_filename,
            ],
        ]);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function authorizedLease(string $leaseId, string $userId): ?Lease
    {
        return Lease::on('lease')
            ->where('id', $leaseId)
            ->where(function ($q) use ($userId) {
                $q->where('lessee_user_id', $userId)
                  ->orWhere('lessor_user_id', $userId);
            })
            ->first();
    }
}
