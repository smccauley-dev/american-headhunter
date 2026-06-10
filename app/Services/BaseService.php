<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

abstract class BaseService
{
    protected function cache(string $key, \Closure $callback, int $ttlMinutes = 10): mixed
    {
        return Cache::store('valkey')->remember($key, now()->addMinutes($ttlMinutes), $callback);
    }

    protected function cacheForever(string $key, \Closure $callback): mixed
    {
        return Cache::store('valkey')->rememberForever($key, $callback);
    }

    protected function invalidate(string ...$keys): void
    {
        foreach ($keys as $key) {
            Cache::store('valkey')->forget($key);
        }
    }

    protected function invalidatePattern(string $pattern): void
    {
        $valkey = Cache::store('valkey')->getRedis();
        $cursor = 0;
        do {
            [$cursor, $keys] = $valkey->scan($cursor, ['match' => $pattern, 'count' => 100]);
            if (! empty($keys)) {
                $valkey->del($keys);
            }
        } while ($cursor != 0);
    }
}
