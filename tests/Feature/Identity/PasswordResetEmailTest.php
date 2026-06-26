<?php

namespace Tests\Feature\Identity;

use App\Jobs\Identity\SendPasswordResetJob;
use App\Models\Identity\PasswordResetToken;
use App\Models\Identity\User;
use App\Services\Audit\AuditService;
use App\Services\Identity\VerificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Regression: the admin "Force Password Reset" action calls
 * VerificationService::sendPasswordResetEmail(). The method was missing
 * entirely, so the button threw BadMethodCallException, no reset email was
 * ever sent, and the target user could never set a new password to log in.
 *
 * Isolation: identity connection wrapped in a transaction rolled back in
 * tearDown; AuditService mocked; Queue faked so no mail is dispatched.
 */
class PasswordResetEmailTest extends TestCase
{
    private VerificationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        DB::connection('identity')->beginTransaction();
        Queue::fake();

        $this->service = new VerificationService(Mockery::mock(AuditService::class));
    }

    protected function tearDown(): void
    {
        try {
            DB::connection('identity')->rollBack();
        } catch (\Throwable) {}

        Mockery::close();
        parent::tearDown();
    }

    private function makeUser(): User
    {
        $id = (string) Str::uuid();
        DB::connection('identity')->table('users')->insert([
            'id'            => $id,
            'email'         => "reset-{$id}@test.invalid",
            'password_hash' => 'placeholder-hash',
            'status'        => 'active',
            'account_type'  => 'hunter',
        ]);

        return User::findOrFail($id);
    }

    public function test_it_issues_a_token_and_dispatches_the_reset_email(): void
    {
        $user = $this->makeUser();

        $this->service->sendPasswordResetEmail($user);

        $this->assertSame(1, PasswordResetToken::where('user_id', $user->id)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->count());

        Queue::assertPushed(
            SendPasswordResetJob::class,
            fn (SendPasswordResetJob $job) => $job->userId === $user->id
        );
    }

    public function test_it_invalidates_a_prior_unused_token(): void
    {
        $user = $this->makeUser();

        $this->service->sendPasswordResetEmail($user);
        $this->service->sendPasswordResetEmail($user);

        // The first token's expiry is pulled back, leaving exactly one live token.
        $this->assertSame(2, PasswordResetToken::where('user_id', $user->id)->count());
        $this->assertSame(1, PasswordResetToken::where('user_id', $user->id)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->count());
    }
}
