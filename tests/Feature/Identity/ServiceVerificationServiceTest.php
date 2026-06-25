<?php

namespace Tests\Feature\Identity;

use App\Models\Identity\FirstResponderVerification;
use App\Models\Identity\User;
use App\Models\Identity\VeteranVerification;
use App\Services\Identity\ServiceVerificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Slice 2: ServiceVerificationService. Approving a veteran verification flips the
 * user flag and applies the configured veteran promotion (a promotion_claim);
 * rejecting a first-responder verification leaves the flag untouched and claims
 * nothing. Runs against the real identity/billing connections, so rows are
 * force-cleaned in tearDown (no DatabaseTransactions).
 */
class ServiceVerificationServiceTest extends TestCase
{
    private string $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = $this->makeHunter();
    }

    protected function tearDown(): void
    {
        DB::connection('billing')->table('promotion_claims')->where('user_id', $this->userId)->delete();
        DB::connection('identity')->table('veteran_verifications')->where('user_id', $this->userId)->delete();
        DB::connection('identity')->table('first_responder_verifications')->where('user_id', $this->userId)->delete();
        DB::connection('identity')->table('user_profiles')->where('user_id', $this->userId)->delete();
        DB::connection('identity')->table('users')->where('id', $this->userId)->delete();

        parent::tearDown();
    }

    private function makeHunter(): string
    {
        $id = (string) Str::uuid();
        DB::connection('identity')->table('users')->insert([
            'id'                => $id,
            'email'             => "vet-{$id}@test.invalid",
            'password_hash'     => bcrypt('Password1!local'),
            'status'            => 'active',
            'account_type'      => 'hunter',
            'email_verified_at' => now(),
            'trust_score'       => 100,
        ]);
        DB::connection('identity')->table('user_profiles')->insert([
            'user_id'    => $id,
            'first_name' => 'Vet',
            'last_name'  => 'Tester',
        ]);

        return $id;
    }

    private function service(): ServiceVerificationService
    {
        return app(ServiceVerificationService::class);
    }

    private function claimCount(): int
    {
        return DB::connection('billing')->table('promotion_claims')->where('user_id', $this->userId)->count();
    }

    public function test_approving_veteran_flips_flag_and_grants_promotion(): void
    {
        $user    = User::on('identity')->find($this->userId);
        $service = $this->service();

        $record = $service->createPending($user, ServiceVerificationService::TYPE_VETERAN, 'dd214_upload');
        $this->assertInstanceOf(VeteranVerification::class, $record);
        $this->assertSame('pending', $record->status);
        $this->assertSame(0, $this->claimCount(), 'no promo claimed while pending');

        $service->approve($record, $this->userId);

        $record->refresh();
        $this->assertSame('approved', $record->status);
        $this->assertNotNull($record->verified_at);

        $this->assertTrue((bool) User::on('identity')->find($this->userId)->is_veteran, 'is_veteran flips on approval');
        $this->assertSame(1, $this->claimCount(), 'the configured veteran promotion is claimed on approval');
    }

    public function test_rejecting_first_responder_leaves_flag_off_and_claims_nothing(): void
    {
        $user    = User::on('identity')->find($this->userId);
        $service = $this->service();

        $record = $service->createPending($user, ServiceVerificationService::TYPE_FIRST_RESPONDER, 'credential_upload');
        $this->assertInstanceOf(FirstResponderVerification::class, $record);

        $service->reject($record, $this->userId);

        $record->refresh();
        $this->assertSame('rejected', $record->status);
        $this->assertNull($record->verified_at);

        $this->assertFalse((bool) User::on('identity')->find($this->userId)->is_first_responder, 'flag stays off on rejection');
        $this->assertSame(0, $this->claimCount(), 'rejection claims no promotion');
    }
}
