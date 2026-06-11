<?php

namespace Tests\Feature\Api;

use App\Jobs\Lease\ProcessDropboxSignWebhook;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Tests the POST /api/webhooks/dropbox-sign endpoint.
 *
 * Isolation: Queue::fake() prevents ProcessDropboxSignWebhook from executing.
 * No DB fixtures required — HMAC logic is pure and dispatch is inspected via Queue.
 */
class DropboxSignWebhookTest extends TestCase
{
    private string $testApiKey = 'wh-test-secret-key';

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.dropbox_sign.api_key', $this->testApiKey);
        Config::set('services.dropbox_sign.client_id', 'test-client');
        Config::set('services.dropbox_sign.test_mode', true);
        Queue::fake();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function buildPayload(string $eventType, string $signatureRequestId = 'sr_abc123'): array
    {
        $eventTime = (string) now()->timestamp;
        $eventHash = hash_hmac('sha256', $eventTime . $eventType, $this->testApiKey);

        return [
            'event' => [
                'event_type' => $eventType,
                'event_time' => $eventTime,
                'event_hash' => $eventHash,
                'event_metadata' => [],
            ],
            'signature_request' => [
                'signature_request_id' => $signatureRequestId,
                'title'                => 'Test Lease',
                'signatures'           => [],
            ],
        ];
    }

    private function postWebhook(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->post('/api/webhooks/dropbox-sign', [
            'json' => json_encode($payload),
        ]);
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_valid_signed_event_returns_200_and_dispatches_job(): void
    {
        $response = $this->postWebhook($this->buildPayload('signature_request_signed'));

        $response->assertStatus(200);
        $response->assertSeeText('Hello API Event Received');
        Queue::assertPushedOn('priority', ProcessDropboxSignWebhook::class);
    }

    public function test_valid_all_signed_event_returns_200_and_dispatches_job(): void
    {
        $response = $this->postWebhook($this->buildPayload('signature_request_all_signed'));

        $response->assertStatus(200);
        $response->assertSeeText('Hello API Event Received');
        Queue::assertPushedOn('priority', ProcessDropboxSignWebhook::class);
    }

    public function test_invalid_hmac_returns_200_but_does_not_dispatch_job(): void
    {
        $payload = $this->buildPayload('signature_request_signed');
        $payload['event']['event_hash'] = 'tampered-hash-value';

        $response = $this->postWebhook($payload);

        $response->assertStatus(200);
        $response->assertSeeText('Hello API Event Received');
        Queue::assertNothingPushed();
    }

    public function test_missing_json_field_returns_200_without_dispatch(): void
    {
        $response = $this->post('/api/webhooks/dropbox-sign', []);

        $response->assertStatus(200);
        $response->assertSeeText('Hello API Event Received');
        Queue::assertNothingPushed();
    }

    public function test_malformed_json_returns_200_without_dispatch(): void
    {
        $response = $this->post('/api/webhooks/dropbox-sign', [
            'json' => '{not valid json !!',
        ]);

        $response->assertStatus(200);
        Queue::assertNothingPushed();
    }

    public function test_unknown_event_type_returns_200_without_dispatch(): void
    {
        $payload = $this->buildPayload('signature_request_viewed');

        $response = $this->postWebhook($payload);

        $response->assertStatus(200);
        Queue::assertNothingPushed();
    }

    public function test_webhook_requires_no_auth_header(): void
    {
        // Dropbox Sign webhooks come without any Authorization header — must be accepted
        $payload = $this->buildPayload('signature_request_signed');

        $response = $this->postWebhook($payload);

        $response->assertStatus(200);
    }
}
