<?php

namespace Tests\Unit;

use App\Services\Lease\DropboxSignService;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class DropboxSignServiceTest extends TestCase
{
    private DropboxSignService $service;
    private string $testApiKey = 'test-api-key-for-hmac';

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.dropbox_sign.api_key', $this->testApiKey);
        Config::set('services.dropbox_sign.client_id', 'test-client-id');
        Config::set('services.dropbox_sign.test_mode', true);
        $this->service = new DropboxSignService();
    }

    public function test_verifyWebhookSignature_returns_true_for_correct_hmac(): void
    {
        $eventTime = '1749600000';
        $eventType = 'signature_request_signed';
        $expected  = hash_hmac('sha256', $eventTime . $eventType, $this->testApiKey);

        $this->assertTrue($this->service->verifyWebhookSignature($eventTime, $eventType, $expected));
    }

    public function test_verifyWebhookSignature_returns_false_for_wrong_hmac(): void
    {
        $eventTime = '1749600000';
        $eventType = 'signature_request_signed';

        $this->assertFalse($this->service->verifyWebhookSignature($eventTime, $eventType, 'wrong-hash'));
    }

    public function test_verifyWebhookSignature_is_sensitive_to_event_time(): void
    {
        $eventType   = 'signature_request_signed';
        $correctTime = '1749600000';
        $wrongTime   = '1749600001';
        $hashForCorrect = hash_hmac('sha256', $correctTime . $eventType, $this->testApiKey);

        $this->assertFalse($this->service->verifyWebhookSignature($wrongTime, $eventType, $hashForCorrect));
    }

    public function test_verifyWebhookSignature_is_sensitive_to_event_type(): void
    {
        $eventTime      = '1749600000';
        $hashForSigned  = hash_hmac('sha256', $eventTime . 'signature_request_signed', $this->testApiKey);

        $this->assertFalse($this->service->verifyWebhookSignature($eventTime, 'signature_request_all_signed', $hashForSigned));
    }

    public function test_verifyWebhookSignature_rejects_empty_hash(): void
    {
        $eventTime = '1749600000';
        $eventType = 'signature_request_signed';

        $this->assertFalse($this->service->verifyWebhookSignature($eventTime, $eventType, ''));
    }
}
