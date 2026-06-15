<?php

namespace Tests\Feature\Documents;

use App\Jobs\Documents\ScanDocumentForViruses;
use App\Services\Documents\DocumentService;
use App\Services\Documents\VirusScanService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * ScanDocumentForViruses job — covers the three handle() outcomes:
 * scanner disabled (dev) → ready, clean → ready, infected → quarantined.
 *
 * Storage::fake() so no real files are written; Queue::fake() so storeRawBytes()
 * does not auto-run the scan we're testing. Document rows are cleaned up in tearDown.
 */
class ScanDocumentForVirusesTest extends TestCase
{
    private DocumentService $documents;
    private string $ownerId;
    private ?string $documentId = null;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        $this->documents = app(DocumentService::class);
        $this->ownerId   = (string) Str::uuid();
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

    private function makeDocument(): string
    {
        $doc = $this->documents->storeRawBytes(
            bytes:        'PDF-BYTES',
            ownerUserId:  $this->ownerId,
            documentType: 'contract',
            filename:     'test.pdf',
        );

        return $this->documentId = $doc->id;
    }

    private function row(string $id): object
    {
        return DB::connection('documents')->table('documents')->where('id', $id)->first();
    }

    public function test_disabled_scanner_marks_document_ready(): void
    {
        config(['services.clamav.enabled' => false]);
        $id = $this->makeDocument();

        (new ScanDocumentForViruses($id))->handle($this->documents, new VirusScanService());

        $row = $this->row($id);
        $this->assertSame('ready', $row->status);
        $this->assertSame('clean', $row->virus_scan_status);
    }

    public function test_clean_file_is_marked_ready(): void
    {
        $scanner = Mockery::mock(VirusScanService::class);
        $scanner->shouldReceive('enabled')->andReturnTrue();
        $scanner->shouldReceive('scan')->andReturn(VirusScanService::CLEAN);

        $id = $this->makeDocument();

        (new ScanDocumentForViruses($id))->handle($this->documents, $scanner);

        $row = $this->row($id);
        $this->assertSame('ready', $row->status);
        $this->assertSame('clean', $row->virus_scan_status);
    }

    public function test_infected_file_is_quarantined(): void
    {
        $scanner = Mockery::mock(VirusScanService::class);
        $scanner->shouldReceive('enabled')->andReturnTrue();
        $scanner->shouldReceive('scan')->andReturn(VirusScanService::INFECTED);

        $id = $this->makeDocument();

        (new ScanDocumentForViruses($id))->handle($this->documents, $scanner);

        $row = $this->row($id);
        $this->assertSame('quarantined', $row->status);
        $this->assertSame('infected', $row->virus_scan_status);
    }
}
