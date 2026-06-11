<?php

namespace Tests\Feature\Documents;

use App\Services\Documents\DocumentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * DocumentService::storeRawBytes() integration test.
 *
 * Uses Storage::fake() so no files are written to real disk.
 * Uses Queue::fake() to prevent ScanDocumentForViruses from running.
 * Cleans up the Document record in tearDown.
 */
class StoreRawBytesTest extends TestCase
{
    private string $ownerId;
    private ?string $documentId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ownerId = (string) Str::uuid();
        Storage::fake('local');
        Queue::fake();
    }

    protected function tearDown(): void
    {
        if ($this->documentId) {
            DB::connection('documents')
                ->table('documents')
                ->where('id', $this->documentId)
                ->delete();
        }
        try { DB::connection('documents')->disconnect(); } catch (\Throwable) {}
        parent::tearDown();
    }

    public function test_storeRawBytes_creates_document_record_with_correct_attributes(): void
    {
        $bytes    = '%PDF-1.4 fake pdf content for testing';
        $filename = 'signed_lease_abc12345.pdf';

        $doc = app(DocumentService::class)->storeRawBytes(
            bytes:        $bytes,
            ownerUserId:  $this->ownerId,
            documentType: 'contract',
            filename:     $filename,
        );

        $this->documentId = $doc->id;

        $this->assertNotNull($doc->id);
        $this->assertSame($this->ownerId, $doc->owner_user_id);
        $this->assertSame('contract', $doc->document_type);
        $this->assertSame($filename, $doc->original_filename);
        $this->assertSame('application/pdf', $doc->mime_type);
        $this->assertSame(strlen($bytes), $doc->size_bytes);
        $this->assertSame('processing', $doc->status);
        $this->assertSame(hash('sha256', $bytes), $doc->checksum_sha256);
        $this->assertFalse((bool) $doc->is_public);
    }

    public function test_storeRawBytes_writes_file_to_storage(): void
    {
        $bytes = '%PDF-1.4 another test pdf';

        $doc = app(DocumentService::class)->storeRawBytes(
            bytes:        $bytes,
            ownerUserId:  $this->ownerId,
            documentType: 'contract',
            filename:     'test.pdf',
        );

        $this->documentId = $doc->id;

        Storage::disk('local')->assertExists($doc->storage_key);
    }

    public function test_storeRawBytes_dispatches_virus_scan_job(): void
    {
        $bytes = '%PDF-1.4 virus scan test';

        $doc = app(DocumentService::class)->storeRawBytes(
            bytes:        $bytes,
            ownerUserId:  $this->ownerId,
            documentType: 'contract',
            filename:     'scan.pdf',
        );

        $this->documentId = $doc->id;

        Queue::assertPushed(\App\Jobs\Documents\ScanDocumentForViruses::class, function ($job) use ($doc) {
            return $job->documentId === $doc->id;
        });
    }

    public function test_storeRawBytes_accepts_custom_mime_type(): void
    {
        $bytes = 'plain text content';

        $doc = app(DocumentService::class)->storeRawBytes(
            bytes:        $bytes,
            ownerUserId:  $this->ownerId,
            documentType: 'other',
            filename:     'data.txt',
            mimeType:     'text/plain',
        );

        $this->documentId = $doc->id;

        $this->assertSame('text/plain', $doc->mime_type);
    }

    public function test_storeRawBytes_storage_key_uses_owner_uuid_prefix(): void
    {
        $bytes = '%PDF test';

        $doc = app(DocumentService::class)->storeRawBytes(
            bytes:        $bytes,
            ownerUserId:  $this->ownerId,
            documentType: 'contract',
            filename:     'contract.pdf',
        );

        $this->documentId = $doc->id;

        $this->assertStringStartsWith("documents/{$this->ownerId}/", $doc->storage_key);
        $this->assertStringEndsWith('.pdf', $doc->storage_key);
    }
}
