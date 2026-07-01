<?php

namespace Tests\Feature\Cache;

use App\Services\BaseService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * BaseService caches against Valkey Cluster 2 (Cache::store('valkey')), which
 * SERIALIZES its values — unlike the `array` driver the rest of the suite runs
 * on. With config('cache.serializable_classes') === false (the secure default,
 * which refuses to reconstruct arbitrary classes on unserialize) an *object*
 * round-tripped through Valkey comes back as __PHP_Incomplete_Class. Laravel's
 * plain remember() would hand that husk to the caller and blow its return type
 * — the /member/harvest 500. BaseService::remember() treats the husk as a miss
 * and recomputes.
 *
 * The array-driver suite cannot see this class of bug because array never
 * serializes. This test forces the real Valkey store so it actually reproduces
 * the serialization behavior, and self-skips where that store can't (e.g. run
 * outside the container, or if the setting were ever flipped) so a green pass is
 * never meaningless.
 */
class BaseServiceCacheTest extends TestCase
{
    private function service(): object
    {
        return new class extends BaseService
        {
            public function get(string $key, \Closure $cb): mixed
            {
                return $this->cache($key, $cb, 5);
            }

            public function bust(string $key): void
            {
                $this->invalidate($key);
            }
        };
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (config('cache.serializable_classes') !== false) {
            $this->markTestSkipped('cache.serializable_classes is not false — the husk is not reproducible.');
        }

        // Confirm the live store genuinely reproduces the husk before asserting
        // the fix converts it — otherwise this test proves nothing.
        $probe = 'basesvc-probe:'.Str::uuid();
        try {
            Cache::store('valkey')->put($probe, new \stdClass, 60);
            $back = Cache::store('valkey')->get($probe);
            Cache::store('valkey')->forget($probe);
        } catch (\Throwable $e) {
            $this->markTestSkipped('Valkey store unavailable: '.$e->getMessage());
        }

        if (! $back instanceof \__PHP_Incomplete_Class) {
            $this->markTestSkipped('Valkey store did not reproduce __PHP_Incomplete_Class (got '.get_debug_type($back).').');
        }
    }

    public function test_warm_object_hit_is_recomputed_not_handed_back_as_a_husk(): void
    {
        $svc = $this->service();
        $key = 'basesvc-object:'.Str::uuid();

        // Populating call — returns the live closure value and writes it to Valkey.
        $first = $svc->get($key, fn () => new \stdClass);
        $this->assertInstanceOf(\stdClass::class, $first);

        // Warm hit — the raw Valkey value is now a husk. remember() must detect
        // it and recompute, so the caller still gets a live object (never the
        // __PHP_Incomplete_Class that caused the /member/harvest 500).
        $second = $svc->get($key, fn () => new \stdClass);
        $this->assertInstanceOf(\stdClass::class, $second);
        $this->assertNotInstanceOf(\__PHP_Incomplete_Class::class, $second);

        $svc->bust($key);
    }

    public function test_warm_array_hit_is_served_from_cache_not_recomputed(): void
    {
        $svc = $this->service();
        $key = 'basesvc-array:'.Str::uuid();

        $calls = 0;
        $make = function () use (&$calls) {
            $calls++;

            return ['answer' => 42];
        };

        // Arrays survive the Valkey round-trip (no husk), so the warm hit must be
        // a genuine hit — the closure runs exactly once. This is why effective
        // caches store arrays/scalars, not Eloquent objects.
        $this->assertSame(['answer' => 42], $svc->get($key, $make));
        $this->assertSame(['answer' => 42], $svc->get($key, $make));
        $this->assertSame(1, $calls, 'a cached array must be served from Valkey, not recomputed');

        $svc->bust($key);
    }
}
