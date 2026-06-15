<?php

namespace Tests\Unit;

use App\Casts\PgTextArray;
use App\Models\Platform\PromotionalPeriod;
use PHPUnit\Framework\TestCase;

class PgTextArrayTest extends TestCase
{
    private function cast(): PgTextArray
    {
        return new PgTextArray();
    }

    private function model(): PromotionalPeriod
    {
        return new PromotionalPeriod();
    }

    public function test_get_returns_empty_array_for_null_and_empty_literals(): void
    {
        $this->assertSame([], $this->cast()->get($this->model(), 'k', null, []));
        $this->assertSame([], $this->cast()->get($this->model(), 'k', '', []));
        $this->assertSame([], $this->cast()->get($this->model(), 'k', '{}', []));
    }

    public function test_get_parses_unquoted_elements(): void
    {
        $this->assertSame(
            ['landowner', 'hunter'],
            $this->cast()->get($this->model(), 'k', '{landowner,hunter}', []),
        );
    }

    public function test_get_parses_quoted_elements_with_commas(): void
    {
        $this->assertSame(
            ['c, d', 'e'],
            $this->cast()->get($this->model(), 'k', '{"c, d",e}', []),
        );
    }

    public function test_set_returns_null_for_null(): void
    {
        $this->assertNull($this->cast()->set($this->model(), 'k', null, []));
    }

    public function test_set_builds_literal_and_quotes_when_needed(): void
    {
        $this->assertSame('{a,b}', $this->cast()->set($this->model(), 'k', ['a', 'b'], []));
        $this->assertSame('{"c, d"}', $this->cast()->set($this->model(), 'k', ['c, d'], []));
    }

    public function test_round_trips(): void
    {
        $original = ['landowner', 'hunter', 'a "b"'];
        $literal  = $this->cast()->set($this->model(), 'k', $original, []);

        $this->assertSame($original, $this->cast()->get($this->model(), 'k', $literal, []));
    }
}
