<?php

namespace App\Jobs\Lease;

use App\Models\Documents\EsignatureRequest;
use App\Models\Documents\EsignatureSigner;
use App\Models\Lease\SignatureEvent;
use App\Services\Documents\DocumentService;
use App\Services\Lease\DropboxSignService;
use App\Services\Lease\EsignatureService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessDropboxSignWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 120;

    public function __construct(
        private readonly string $eventType,
        private readonly array  $payload,
    ) {
        $this->onQueue('priority');
    }

    public function handle(
        DropboxSignService $dropboxSign,
        DocumentService    $documentService,
        EsignatureService  $esignatureService,
    ): void {
        $signatureRequestId = $this->payload['signature_request']['signature_request_id'] ?? null;

        if (! $signatureRequestId) {
            Log::warning('ProcessDropboxSignWebhook: missing signature_request_id', ['payload' => $this->payload]);
            return;
        }

        $esigRequest = EsignatureRequest::where('provider_signature_request_id', $signatureRequestId)
            ->where('provider', 'dropbox_sign')
            ->first();

        if (! $esigRequest) {
            Log::warning('ProcessDropboxSignWebhook: no EsignatureRequest found', [
                'signature_request_id' => $signatureRequestId,
            ]);
            return;
        }

        match ($this->eventType) {
            'signature_request_signed'    => $this->handleSigned($esigRequest, $esignatureService),
            'signature_request_all_signed' => $this->handleAllSigned($esigRequest, $dropboxSign, $documentService, $esignatureService),
            default => null,
        };
    }

    private function handleSigned(EsignatureRequest $esigRequest, EsignatureService $esignatureService): void
    {
        // Find which signer signed by matching provider_signer_id from the payload
        foreach ($this->payload['signature_request']['signatures'] ?? [] as $sig) {
            if (($sig['status_code'] ?? '') !== 'signed') {
                continue;
            }

            $signer = EsignatureSigner::where('request_id', $esigRequest->id)
                ->where('provider_signer_id', $sig['signature_id'])
                ->first();

            if (! $signer || $signer->status === 'signed') {
                continue;
            }

            $signer->status    = 'signed';
            $signer->signed_at = now();
            $signer->save();

            SignatureEvent::create([
                'lease_id'    => $esigRequest->lease_id,
                'user_id'     => $signer->user_id,
                'provider'    => 'dropbox_sign',
                'event_type'  => 'signed',
                'occurred_at' => now(),
            ]);
        }
    }

    private function handleAllSigned(
        EsignatureRequest $esigRequest,
        DropboxSignService $dropboxSign,
        DocumentService    $documentService,
        EsignatureService  $esignatureService,
    ): void {
        // Mark all signers signed (idempotent — handleSigned may have already run)
        EsignatureSigner::where('request_id', $esigRequest->id)
            ->where('status', '!=', 'signed')
            ->update(['status' => 'signed', 'signed_at' => now()]);

        // Download the completed PDF and store it
        try {
            $pdfBytes = $dropboxSign->downloadSignedPdf($esigRequest->provider_signature_request_id);

            $signedDoc = $documentService->storeRawBytes(
                bytes:        $pdfBytes,
                ownerUserId:  $esigRequest->requester_user_id,
                documentType: 'signed_lease_contract',
                filename:     'signed_lease_' . substr($esigRequest->lease_id, 0, 8) . '.pdf',
            );

            $esigRequest->signed_document_id = $signedDoc->id;
        } catch (\Throwable $e) {
            Log::error('ProcessDropboxSignWebhook: failed to download signed PDF', [
                'signature_request_id' => $esigRequest->provider_signature_request_id,
                'error'                => $e->getMessage(),
            ]);
        }

        $esigRequest->status       = 'completed';
        $esigRequest->completed_at = now();
        $esigRequest->save();

        SignatureEvent::create([
            'lease_id'    => $esigRequest->lease_id,
            'user_id'     => $esigRequest->requester_user_id,
            'provider'    => 'dropbox_sign',
            'event_type'  => 'completed',
            'occurred_at' => now(),
        ]);

        // Activate the lease via EsignatureService — reuse the same activation path
        app(\App\Services\Lease\LeaseService::class)->activate($esigRequest->lease_id);

        \App\Models\Lease\LeaseHunter::where('lease_id', $esigRequest->lease_id)
            ->where('role', 'primary')
            ->update(['is_approved' => true, 'approved_at' => now()]);

        try {
            app(\App\Services\Audit\AuditService::class)->log(
                eventType:      'lease.activated',
                sourceDatabase: 'ah_lease',
                tableName:      'leases',
                recordId:       $esigRequest->lease_id,
                actionSummary:  'Lease activated after all Dropbox Sign signatures collected',
            );
        } catch (\Throwable) {}
    }
}
