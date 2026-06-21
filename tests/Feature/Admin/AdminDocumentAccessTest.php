<?php

namespace Tests\Feature\Admin;

use App\Models\Identity\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SEC-050: the admin document view/download routes serve applicant PII (driver's
 * license and hunting-license images in the admin hunter roster). They are now
 * routed through AdminDocumentController, which audit-logs every access. These
 * tests assert an audit_log row is written for both verbs.
 *
 * The routes are gated ['db.system','auth:web']; the web guard only resolves
 * staff (User::canAccessPanel), so we act as a web-guard staff user. Runs against
 * the real identity/documents/audit connections, so rows are force-cleaned in
 * tearDown (no DatabaseTransactions).
 */
class AdminDocumentAccessTest extends TestCase
{
    private string $actorId;
    private string $documentId;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->actorId    = $this->makeStaffUser();
        $this->documentId = $this->makeDocument();
    }

    protected function tearDown(): void
    {
        DB::connection('audit')->table('audit_log')->where('record_id', $this->documentId)->delete();
        DB::connection('documents')->table('documents')->where('id', $this->documentId)->delete();
        DB::connection('identity')->table('user_profiles')->where('user_id', $this->actorId)->delete();
        DB::connection('identity')->table('user_roles')->where('user_id', $this->actorId)->delete();
        DB::connection('identity')->table('users')->where('id', $this->actorId)->delete();

        parent::tearDown();
    }

    private function makeStaffUser(): string
    {
        $roleId = (string) DB::connection('identity')->table('roles')->where('name', 'super_admin')->value('id');

        $id = (string) Str::uuid();
        DB::connection('identity')->table('users')->insert([
            'id'                => $id,
            'email'             => "admin-doc-{$id}@test.invalid",
            'password_hash'     => bcrypt('Password1!local'),
            'status'            => 'active',
            'account_type'      => 'staff',
            'email_verified_at' => now(),
            'trust_score'       => 100,
        ]);
        DB::connection('identity')->table('user_profiles')->insert([
            'user_id'    => $id,
            'first_name' => 'Doc',
            'last_name'  => 'Admin',
        ]);
        DB::connection('identity')->table('user_roles')->insert([
            'user_id' => $id,
            'role_id' => $roleId,
        ]);

        return $id;
    }

    private function makeDocument(): string
    {
        $key = 'docs/' . Str::random(12) . '.pdf';
        Storage::disk('local')->put($key, 'PII-bytes');

        $id = (string) Str::uuid();
        DB::connection('documents')->table('documents')->insert([
            'id'                => $id,
            'owner_user_id'     => $this->actorId,
            'document_type'     => 'id_document',
            'status'            => 'ready',
            'storage_provider'  => 'garage',
            'storage_key'       => $key,
            'original_filename' => 'license.pdf',
            'mime_type'         => 'application/pdf',
        ]);

        return $id;
    }

    private function actAsStaff(): void
    {
        $this->actingAs(User::on('identity')->find($this->actorId), 'web');
    }

    private function auditCount(string $eventType): int
    {
        return DB::connection('audit')->table('audit_log')
            ->where('record_id', $this->documentId)
            ->where('event_type', $eventType)
            ->count();
    }

    public function test_view_is_audit_logged(): void
    {
        $this->actAsStaff();

        $this->get(route('admin.documents.view', $this->documentId))->assertOk();

        $this->assertSame(1, $this->auditCount('document.viewed'), 'an inline view writes a document.viewed audit row');
    }

    public function test_download_is_audit_logged(): void
    {
        $this->actAsStaff();

        $this->get(route('admin.documents.download', $this->documentId))->assertOk();

        $this->assertSame(1, $this->auditCount('document.downloaded'), 'a download writes a document.downloaded audit row');
    }

    public function test_guest_is_rejected(): void
    {
        // No web-guard session — the auth:web gate must block access.
        $response = $this->get(route('admin.documents.view', $this->documentId));

        $this->assertContains($response->getStatusCode(), [302, 401, 403], 'unauthenticated access is denied');
        $this->assertSame(0, $this->auditCount('document.viewed'), 'a blocked request logs nothing');
    }
}
