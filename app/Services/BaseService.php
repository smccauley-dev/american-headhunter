<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

abstract class BaseService
{
    protected function cache(string $key, \Closure $callback, int $ttlMinutes = 10): mixed
    {
        return $this->remember($key, $callback, $ttlMinutes);
    }

    protected function cacheForever(string $key, \Closure $callback): mixed
    {
        return $this->remember($key, $callback, null);
    }

    /**
     * Get-or-compute against Valkey Cluster 2, hardened against poisoned hits.
     *
     * A cached *object* (e.g. an Eloquent model or Collection) comes back as
     * __PHP_Incomplete_Class when config('cache.serializable_classes') is false —
     * the secure default that refuses to reconstruct arbitrary classes on
     * unserialize, blocking gadget-chain attacks if APP_KEY leaks. Laravel's
     * remember() would hand that husk straight back to the caller (blowing return
     * types like Collection), so we treat it as a miss and recompute, guaranteeing
     * callers always receive a live value. A genuine cache miss uses a sentinel so
     * a legitimately-cached null is still served as a hit.
     */
    private function remember(string $key, \Closure $callback, ?int $ttlMinutes): mixed
    {
        $store = Cache::store('valkey');
        $miss = "\0ah-cache-miss\0";

        $value = $store->get($key, $miss);

        if ($value === $miss || $value instanceof \__PHP_Incomplete_Class) {
            $value = $callback();

            $ttlMinutes === null
                ? $store->forever($key, $value)
                : $store->put($key, $value, now()->addMinutes($ttlMinutes));
        }

        return $value;
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
