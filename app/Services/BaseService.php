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
        // Use the raw phpredis client, not the Laravel manager/connection wrapper:
        // RedisManager::scan() drops the [cursor, keys] contract and PhpRedisConnection
        // mishandles the iterator, so the wrapped loop silently matches nothing. The
        // raw client with SCAN_RETRY iterates reliably (and never returns empty batches
        // mid-scan). Keys come back fully prefixed; del() takes them as-is. SCAN keeps
        // this non-blocking on large keyspaces (e.g. user_entitlements:*).
        $client = Cache::store('valkey')->getRedis()->connection()->client();
        $client->setOption(\Redis::OPT_SCAN, \Redis::SCAN_RETRY);

        $iterator = null;
        while (($keys = $client->scan($iterator, $pattern, 100)) !== false) {
            if (! empty($keys)) {
                $client->del($keys);
            }
        }
    }
}
