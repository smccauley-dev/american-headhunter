<?php

namespace Tests\Feature\Identity;

use App\Services\Audit\AuditService;
use App\Services\Auth\MfaService;
use App\Services\Identity\UserService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Covers that signup persists the fields the form collects. Home state, date of
 * birth and phone are all load-bearing downstream (home state gatekeeps which
 * listings a member may apply to; DOB/phone feed safety + contact), so a
 * regression that silently dropped them would not surface until much later.
 *
 * Isolation: the identity connection is wrapped in a transaction rolled back in
 * tearDown. AuditService is mocked so create() does not write a permanent row to
 * the immutable audit log.
 */
class UserServiceCreateTest extends TestCase
{
    private UserService $service;

    protected function setUp(): void
    {
        parent::setUp();

        DB::connection('identity')->beginTransaction();

        $audit = Mockery::mock(AuditService::class);
        $audit->shouldReceive('logAccountCreated')->andReturnNull();

        $this->service = new UserService($audit, app(MfaService::class));
    }

    protected function tearDown(): void
    {
        try {
            DB::connection('identity')->rollBack();
        } catch (\Throwable) {}

        Mockery::close();
        parent::tearDown();
    }

    public function test_create_persists_phone_dob_and_home_state(): void
    {
        $email = 'wire-' . Str::uuid() . '@test.invalid';

        $user = $this->service->create([
            'email'         => $email,
            'password'      => 'Sup3rSecret!!',
            'account_type'  => 'hunter',
            'first_name'    => 'Jane',
            'last_name'     => 'Hunter',
            'phone'         => '(512) 555-0144',
            'date_of_birth' => '1990-04-02',
            'state_code'    => 'TX',
            'tos_version'   => '2026-01-01',
        ]);

        $this->assertSame('(512) 555-0144', DB::connection('identity')
            ->table('users')->where('id', $user->id)->value('phone'));

        $profile = DB::connection('identity')
            ->table('user_profiles')->where('user_id', $user->id)->first();

        $this->assertSame('TX', $profile->state_code);
        $this->assertSame('1990-04-02', substr((string) $profile->date_of_birth, 0, 10));
    }
}
