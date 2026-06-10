<?php

namespace App\Services\Mfa;

use App\Contracts\Mfa\MfaMethodContract;
use InvalidArgumentException;

class MfaMethodRegistry
{
    /** @var array<string, MfaMethodContract> */
    private array $methods = [];

    public function register(MfaMethodContract $method): void
    {
        $this->methods[$method->method()] = $method;
    }

    public function get(string $method): MfaMethodContract
    {
        if (! isset($this->methods[$method])) {
            throw new InvalidArgumentException("Unknown MFA method: {$method}");
        }
        return $this->methods[$method];
    }

    public function has(string $method): bool
    {
        return isset($this->methods[$method]);
    }
}
