<?php

namespace App\Services\Lease;

use App\Services\BaseService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DropboxSignService extends BaseService
{
    private readonly string $apiKey;
    private readonly string $clientId;
    private readonly bool   $testMode;

    public function __construct()
    {
        $this->apiKey   = config('services.dropbox_sign.api_key', '');
        $this->clientId = config('services.dropbox_sign.client_id', '');
        $this->testMode = (bool) config('services.dropbox_sign.test_mode', true);
    }

    /**
     * Create an embedded signing envelope with two signers.
     *
     * @param  string  $pdfPath   Absolute filesystem path to the contract PDF
     * @param  string  $subject   Email subject line shown to signers
     * @param  array{user_id: string, name: string, email: string}  $lessor
     * @param  array{user_id: string, name: string, email: string}  $lessee
     * @return array{signature_request_id: string, lessor_signature_id: string, lessee_signature_id: string}
     *
     * @throws \RuntimeException on API error
     */
    public function createEmbeddedEnvelope(
        string $pdfPath,
        string $subject,
        array  $lessor,
        array  $lessee,
    ): array {
        $response = Http::withBasicAuth($this->apiKey, '')
            ->timeout(30)
            ->attach('file[0]', file_get_contents($pdfPath), basename($pdfPath))
            ->post('https://api.hellosign.com/v3/signature_request/send', [
                'client_id'               => $this->clientId,
                'title'                   => $subject,
                'subject'                 => $subject,
                'is_for_embedded_signing' => 1,
                'test_mode'               => $this->testMode ? 1 : 0,
                'signers[0][name]'        => $lessor['name'],
                'signers[0][email_address]' => $lessor['email'],
                'signers[0][order]'       => 0,
                'signers[1][name]'        => $lessee['name'],
                'signers[1][email_address]' => $lessee['email'],
                'signers[1][order]'       => 1,
            ]);

        if (! $response->successful()) {
            Log::error('DropboxSign createEmbeddedEnvelope failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new \RuntimeException('Dropbox Sign API error: ' . $response->status());
        }

        $data    = $response->json();
        $signers = $data['signature_request']['signatures'] ?? [];

        // Dropbox Sign returns signers in the same order we submitted them
        $lessorSig = collect($signers)->firstWhere('signer_email_address', $lessor['email']);
        $lesseeSig = collect($signers)->firstWhere('signer_email_address', $lessee['email']);

        return [
            'signature_request_id' => $data['signature_request']['signature_request_id'],
            'lessor_signature_id'  => $lessorSig['signature_id'] ?? '',
            'lessee_signature_id'  => $lesseeSig['signature_id'] ?? '',
        ];
    }

    /**
     * Get a short-lived (60 min) embedded signing URL for one signer.
     *
     * @throws \RuntimeException on API error
     */
    public function getEmbeddedSigningUrl(string $signatureId): string
    {
        $response = Http::withBasicAuth($this->apiKey, '')
            ->timeout(15)
            ->get("https://api.hellosign.com/v3/embedded/sign_url/{$signatureId}");

        if (! $response->successful()) {
            Log::error('DropboxSign getEmbeddedSigningUrl failed', [
                'signature_id' => $signatureId,
                'status'       => $response->status(),
            ]);
            throw new \RuntimeException('Dropbox Sign API error: ' . $response->status());
        }

        return $response->json('embedded.sign_url');
    }

    /**
     * Download the final signed PDF for a completed signature request.
     * Returns raw binary bytes.
     *
     * @throws \RuntimeException on API error
     */
    public function downloadSignedPdf(string $signatureRequestId): string
    {
        $response = Http::withBasicAuth($this->apiKey, '')
            ->timeout(60)
            ->get("https://api.hellosign.com/v3/signature_request/files/{$signatureRequestId}", [
                'file_type' => 'pdf',
            ]);

        if (! $response->successful()) {
            Log::error('DropboxSign downloadSignedPdf failed', [
                'signature_request_id' => $signatureRequestId,
                'status'               => $response->status(),
            ]);
            throw new \RuntimeException('Dropbox Sign API error: ' . $response->status());
        }

        return $response->body();
    }

    /**
     * Verify an incoming webhook HMAC signature.
     * Dropbox Sign uses: HMAC-SHA256(event_time + event_type, api_key)
     */
    public function verifyWebhookSignature(string $eventTime, string $eventType, string $eventHash): bool
    {
        $expected = hash_hmac('sha256', $eventTime . $eventType, $this->apiKey);

        return hash_equals($expected, $eventHash);
    }
}
