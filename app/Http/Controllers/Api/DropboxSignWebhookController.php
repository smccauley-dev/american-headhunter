<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\Lease\ProcessDropboxSignWebhook;
use App\Services\Lease\DropboxSignService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class DropboxSignWebhookController extends Controller
{
    public function handle(Request $request, DropboxSignService $dropboxSign): Response
    {
        $raw = $request->input('json');

        if (! $raw) {
            Log::warning('DropboxSignWebhook: missing json payload');
            return response('Hello API Event Received', 200);
        }

        $payload = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('DropboxSignWebhook: invalid JSON payload');
            return response('Hello API Event Received', 200);
        }

        $event     = $payload['event'] ?? [];
        $eventType = $event['event_type'] ?? '';
        $eventTime = (string) ($event['event_time'] ?? '');
        $eventHash = $event['event_hash'] ?? '';

        if (! $dropboxSign->verifyWebhookSignature($eventTime, $eventType, $eventHash)) {
            Log::warning('DropboxSignWebhook: HMAC verification failed', [
                'event_type' => $eventType,
                'event_time' => $eventTime,
            ]);
            // Still return 200 — Dropbox Sign will retry on non-200, which we don't want
            return response('Hello API Event Received', 200);
        }

        if (in_array($eventType, ['signature_request_signed', 'signature_request_all_signed'], true)) {
            ProcessDropboxSignWebhook::dispatch($eventType, $payload);
        }

        return response('Hello API Event Received', 200);
    }
}
