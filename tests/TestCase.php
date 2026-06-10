<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Feature tests bypass CSRF — tokens are irrelevant in the test kernel context.
        // Laravel 13: CSRF is implemented as PreventRequestForgery; VerifyCsrfToken is a deprecated alias.
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);
    }
}
