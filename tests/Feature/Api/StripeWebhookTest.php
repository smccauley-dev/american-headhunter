<?php

namespace Tests\Feature\Api;

use App\Jobs\Billing\ProcessStripeWebhook;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Tests the POST /api/webhooks/stripe endpoint.
 *
 * Isolation: Queue::fake() prevents ProcessStripeWebhook from executing.
 * No DB fixtures required — the controller only verifies the signature
 * (Stripe SDK, pure) and dispatches; dispatch is inspected via Queue.
 */
class StripeWebhookTest extends TestCase
{
    private string $webhookSecret = 'whsec_test_secret';

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.stripe.secret', 'sk_test_dummy');
        Config::set('services.stripe.webhook_secret', $this->webhookSecret);
        Queue::fake();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function buildPayload(string $type, array $object = []): string
    {
        return json_encode([
            'id'     => 'evt_' . bin2hex(random_bytes(8)),
            'type'   => $type,
            'object' => 'event',
            'data'   => ['object' => $object ?: ['id' => 'obj_test']],
        ]);
    }

    private function signedHeader(string $payload, ?int $timestamp = null, ?string $secret = null): string
    {
        $timestamp ??= time();
        $secret    ??= $this->webhookSecret;
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        return "t={$timestamp},v1={$signature}";
    }

    private function postWebhook(string $payload, string $signatureHeader): \Illuminate\Testing\TestResponse
    {
        return $this->call(
            'POST',
            '/api/webhooks/stripe',
            [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => $signatureHeader, 'CONTENT_TYPE' => 'application/json'],
            $payload,
        );
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_valid_signed_event_returns_200_and_dispatches_job(): void
    {
        $payload = $this->buildPayload('payment_intent.succeeded', ['id' => 'pi_123']);

        $response = $this->postWebhook($payload, $this->signedHeader($payload));

        $response->assertStatus(200);
        Queue::assertPushedOn('priority', ProcessStripeWebhook::class);
    }

    public function test_invalid_signature_returns_400_and_does_not_dispatch(): void
    {
        $payload = $this->buildPayload('payment_intent.succeeded');

        $response = $this->postWebhook($payload, 't=' . time() . ',v1=tampered');

        $response->assertStatus(400);
        Queue::assertNothingPushed();
    }

    public function test_signature_from_wrong_secret_returns_400(): void
    {
        $payload = $this->buildPayload('customer.subscription.updated');

        $response = $this->postWebhook($payload, $this->signedHeader($payload, secret: 'whsec_wrong'));

        $response->assertStatus(400);
        Queue::assertNothingPushed();
    }

    public function test_stale_timestamp_returns_400(): void
    {
        // Stripe rejects timestamps outside the default 5-minute tolerance.
        $payload    = $this->buildPayload('payment_intent.succeeded');
        $oldTime    = time() - 1000;

        $response = $this->postWebhook($payload, $this->signedHeader($payload, timestamp: $oldTime));

        $response->assertStatus(400);
        Queue::assertNothingPushed();
    }

    public function test_missing_signature_header_returns_400(): void
    {
        $payload = $this->buildPayload('payment_intent.succeeded');

        $response = $this->postWebhook($payload, '');

        $response->assertStatus(400);
        Queue::assertNothingPushed();
    }
}
