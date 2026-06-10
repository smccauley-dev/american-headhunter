<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\DropboxSignWebhookController;
use App\Models\Documents\EsignatureRequest;
use App\Models\Documents\EsignatureSigner;
use App\Services\Lease\DropboxSignService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

/**
 * Local dev tool — simulates a Dropbox Sign webhook without needing ngrok.
 * Fires the webhook payload in-process with a valid HMAC signature.
 *
 * Usage:
 *   php artisan dropbox-sign:simulate {leaseId} --event=signature_request_all_signed
 *   php artisan dropbox-sign:simulate {leaseId} --event=signature_request_signed --signer=lessor
 */
class DropboxSignSimulate extends Command
{
    protected $signature = 'dropbox-sign:simulate
        {leaseId : UUID of the lease to simulate signing for}
        {--event=signature_request_all_signed : Event type to fire}
        {--signer=all : Which signer to mark signed (lessor|lessee|all)}';

    protected $description = 'Simulate a Dropbox Sign webhook event for local dev testing';

    public function handle(DropboxSignService $dropboxSign): int
    {
        $leaseId   = $this->argument('leaseId');
        $eventType = $this->option('event');
        $signerOpt = $this->option('signer');

        $esigRequest = EsignatureRequest::where('lease_id', $leaseId)
            ->where('provider', 'dropbox_sign')
            ->latest('requested_at')
            ->first();

        if (! $esigRequest) {
            $this->error("No Dropbox Sign esignature request found for lease {$leaseId}");
            return self::FAILURE;
        }

        $this->info("Simulating event: {$eventType}");
        $this->info("Signature request: {$esigRequest->provider_signature_request_id}");

        $signers     = $esigRequest->signers()->get();
        $signersList = [];

        foreach ($signers as $signer) {
            $include = match ($signerOpt) {
                'lessor' => $signer->order_num === 1,
                'lessee' => $signer->order_num === 2,
                default  => true,
            };

            $signersList[] = [
                'signature_id'           => $signer->provider_signer_id ?? 'sim_' . $signer->id,
                'signer_email_address'   => $signer->email,
                'signer_name'            => $signer->name,
                'order'                  => $signer->order_num - 1,
                'status_code'            => $include ? 'signed' : 'awaiting_signature',
                'signed_at'              => $include ? now()->timestamp : null,
            ];
        }

        $eventTime = (string) now()->timestamp;
        $eventHash = hash_hmac('sha256', $eventTime . $eventType, config('services.dropbox_sign.api_key', ''));

        $payload = [
            'event' => [
                'event_type'       => $eventType,
                'event_time'       => $eventTime,
                'event_hash'       => $eventHash,
                'event_metadata'   => [],
            ],
            'signature_request' => [
                'signature_request_id' => $esigRequest->provider_signature_request_id,
                'title'                => $esigRequest->subject,
                'signatures'           => $signersList,
            ],
        ];

        $request = Request::create('/api/webhooks/dropbox-sign', 'POST', [
            'json' => json_encode($payload),
        ]);

        $controller = app(DropboxSignWebhookController::class);
        $response   = $controller->handle($request, $dropboxSign);

        $this->info("Webhook response: {$response->getStatusCode()} — {$response->getContent()}");
        $this->info('Job dispatched to queue. Run `php artisan queue:work` to process it.');

        return self::SUCCESS;
    }
}
