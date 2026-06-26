<?php

namespace Tests\Feature\Security;

use App\Database\RlsContext;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SEC-055 regression — RLS context is injected lazily, on connect.
 *
 * The original defect: InjectDatabaseContext eagerly opened every RLS-bearing
 * database per request to set the session variables up front. Under load that
 * exhausted PostgreSQL's connection slots; the connection that lost the race had
 * its context silently skipped, so its RLS reads returned zero rows for an
 * authorized user (a paid deposit rendered as unpaid).
 *
 * The fix: a request-scoped RlsContext singleton is armed by the middleware, and
 * a ConnectionEstablished listener applies it the moment each connection is
 * actually opened (and re-applies on reconnect). This proves the listener wiring:
 * once armed, a freshly-established connection carries the context; until armed,
 * it does not (so console/queue/test paths are unaffected).
 *
 * Reads only `current_setting()` — no table data — so it is correct regardless of
 * which role the test kernel uses.
 */
class RlsContextLazyInjectionTest extends TestCase
{
    private function settingOn(string $connection, string $key): ?string
    {
        return DB::connection($connection)
            ->select("select current_setting('{$key}', true) as v")[0]->v;
    }

    public function test_armed_context_is_applied_when_a_connection_is_established(): void
    {
        $userId = '11111111-1111-1111-1111-111111111111';

        app(RlsContext::class)->set($userId, 'hunter');

        // Force a fresh connect so ConnectionEstablished fires with the context armed.
        DB::purge('billing');

        $this->assertSame($userId, $this->settingOn('billing', 'app.current_user_id'));
        $this->assertSame('hunter', $this->settingOn('billing', 'app.user_role'));
    }

    public function test_unarmed_context_sets_nothing_on_connect(): void
    {
        // RlsContext is a fresh, unarmed singleton for this test (no middleware ran).
        $this->assertFalse(app(RlsContext::class)->isReady());

        DB::purge('billing');

        $this->assertNull($this->settingOn('billing', 'app.current_user_id'));
    }
}
