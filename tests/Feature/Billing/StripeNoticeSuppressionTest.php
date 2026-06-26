<?php

namespace Tests\Feature\Billing;

use App\Services\Billing\StripeService;
use Tests\TestCase;

/**
 * Recent Stripe API versions return a `stripe-notice` response header on the v1
 * Account endpoints (recommending Accounts v2). stripe-php emits it via
 * trigger_error(E_USER_WARNING), which Laravel's error handler promotes to a
 * fatal ErrorException — crashing a Connect onboarding request whose API call
 * actually succeeded. StripeService::withoutStripeNotice() neutralizes it.
 */
class StripeNoticeSuppressionTest extends TestCase
{
    /** A stripe-notice warning raised mid-call must not abort the call. */
    public function test_a_stripe_notice_warning_does_not_abort_the_call(): void
    {
        $value = $this->runWithoutStripeNotice(function () {
            trigger_error('We recommend building your integration using Accounts v2.', \E_USER_WARNING);

            return 'acct_123';
        });

        $this->assertSame('acct_123', $value, 'the call should return normally despite the notice');
    }

    /** The error handler is restored afterward — no leaked global state. */
    public function test_it_restores_the_previous_error_handler(): void
    {
        $before = set_error_handler(static fn () => true);
        restore_error_handler();

        $this->runWithoutStripeNotice(fn () => null);

        $after = set_error_handler(static fn () => true);
        restore_error_handler();

        $this->assertSame($before, $after, 'the surrounding error handler should be left untouched');
    }

    /** A genuine failure inside the call still propagates. */
    public function test_a_real_exception_still_propagates(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->runWithoutStripeNotice(function () {
            throw new \RuntimeException('stripe down');
        });
    }

    private function runWithoutStripeNotice(\Closure $call): mixed
    {
        $method = new \ReflectionMethod(StripeService::class, 'withoutStripeNotice');

        return $method->invoke(app(StripeService::class), $call);
    }
}
