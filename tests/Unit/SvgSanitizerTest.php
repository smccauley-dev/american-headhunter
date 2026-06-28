<?php

namespace Tests\Unit;

use App\Support\SvgSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * SvgSanitizer scrubs admin-pasted icon markup and normalises a full <svg> down
 * to inner markup + an extracted viewBox (the shape GameIcon renders).
 */
class SvgSanitizerTest extends TestCase
{
    public function test_clean_returns_null_for_empty_input(): void
    {
        $this->assertNull(SvgSanitizer::clean(null));
        $this->assertNull(SvgSanitizer::clean('   '));
    }

    public function test_clean_strips_script_and_event_handlers_and_js_uris(): void
    {
        $dirty = '<path d="M0 0" onclick="steal()"/><script>alert(1)</script>'
            . '<a href="javascript:evil()">x</a>';

        $clean = SvgSanitizer::clean($dirty);

        $this->assertStringNotContainsString('<script', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('javascript:', $clean);
        $this->assertStringContainsString('<path d="M0 0"', $clean);
    }

    public function test_normalize_extracts_viewbox_and_inner_from_full_svg(): void
    {
        $raw = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M1 2"/></svg>';

        $norm = SvgSanitizer::normalizeIcon($raw);

        $this->assertSame('0 0 24 24', $norm['icon_viewbox']);
        $this->assertSame('<path d="M1 2"/>', $norm['icon_svg']);
    }

    public function test_normalize_synthesizes_viewbox_from_width_height_when_absent(): void
    {
        $raw = '<svg width="48" height="32"><path d="M0 0"/></svg>';

        $norm = SvgSanitizer::normalizeIcon($raw);

        $this->assertSame('0 0 48 32', $norm['icon_viewbox']);
    }

    public function test_normalize_leaves_viewbox_null_for_bare_markup(): void
    {
        $norm = SvgSanitizer::normalizeIcon('<path d="M5 5"/>');

        $this->assertNull($norm['icon_viewbox']);
        $this->assertSame('<path d="M5 5"/>', $norm['icon_svg']);
    }

    public function test_normalize_returns_nulls_for_empty(): void
    {
        $norm = SvgSanitizer::normalizeIcon('  ');

        $this->assertNull($norm['icon_svg']);
        $this->assertNull($norm['icon_viewbox']);
    }
}
