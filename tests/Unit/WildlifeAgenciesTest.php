<?php

namespace Tests\Unit;

use App\Support\WildlifeAgencies;
use PHPUnit\Framework\TestCase;

class WildlifeAgenciesTest extends TestCase
{
    public function test_resolves_a_known_state_agency(): void
    {
        $this->assertSame('Texas Parks and Wildlife Department', WildlifeAgencies::forState('TX'));
    }

    public function test_is_case_insensitive(): void
    {
        $this->assertSame('Texas Parks and Wildlife Department', WildlifeAgencies::forState('tx'));
    }

    public function test_returns_null_for_unknown_or_empty_state(): void
    {
        $this->assertNull(WildlifeAgencies::forState('ZZ'));
        $this->assertNull(WildlifeAgencies::forState(null));
    }

    public function test_covers_all_fifty_states_plus_dc(): void
    {
        $this->assertCount(51, WildlifeAgencies::names());
    }
}
