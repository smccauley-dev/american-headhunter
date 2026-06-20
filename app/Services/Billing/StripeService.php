<?php

namespace App\Services\Billing;

use Stripe\Event;
use Stripe\Stripe;
use Stripe\Webhook;

/**
 * Thin wrapper around the Stripe SDK. The secret key is read from
 * config('services.stripe.secret') — never hardcoded. Grows as the billing
 * phases land; for now it covers webhook signature verification (Phase 5.3).
 */
class StripeService
{
    public function __construct()
    {
        Stripe::setApiKey((string) config('services.stripe.secret'));
    }

    /**
     * Verify a raw webhook payload against the Stripe-Signature header and
     * return the parsed event.
     *
     * @throws \UnexpectedValueException                       on malformed payload
     * @throws \Stripe\Exception\SignatureVerificationException on signature mismatch
     */
    public function constructWebhookEvent(string $payload, string $signature): Event
    {
        return Webhook::constructEvent(
            $payload,
            $signature,
            (string) config('services.stripe.webhook_secret'),
        );
    }
}
