<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\Billing\ProcessStripeWebhook;
use App\Services\Billing\StripeService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;

class StripeWebhookController extends Controller
{
    public function handle(Request $request, StripeService $stripe): Response
    {
        $payload   = $request->getContent();
        $signature = $request->header('Stripe-Signature', '');

        try {
            $event = $stripe->constructWebhookEvent($payload, $signature);
        } catch (\UnexpectedValueException $e) {
            Log::warning('StripeWebhook: invalid payload', ['error' => $e->getMessage()]);
            return response('Invalid payload', 400);
        } catch (SignatureVerificationException $e) {
            Log::warning('StripeWebhook: signature verification failed');
            return response('Invalid signature', 400);
        }

        // Hand off immediately — all DB work happens on the priority queue,
        // where the worker runs under the trusted ah_system role.
        ProcessStripeWebhook::dispatch(
            $event->id,
            $event->type,
            $event->data->object->toArray(),
        );

        return response('ok', 200);
    }
}
