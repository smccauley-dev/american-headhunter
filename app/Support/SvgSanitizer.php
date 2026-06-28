<?php

namespace App\Support;

/**
 * Best-effort sanitizer for admin-supplied inline SVG icon markup. Game-type
 * icons are pasted by staff and rendered inline on public pages, so we strip the
 * obvious script vectors before storing: <script>/<foreignObject> elements,
 * on* event-handler attributes, and javascript: URIs. This is defence in depth
 * on top of the admin-only access gate — it is not a general HTML sanitizer.
 */
class SvgSanitizer
{
    public static function clean(?string $svg): ?string
    {
        if ($svg === null) {
            return null;
        }

        $svg = trim($svg);

        if ($svg === '') {
            return null;
        }

        // Drop dangerous elements wholesale (with or without content).
        $svg = preg_replace('#<\s*(script|foreignObject|iframe|embed|object|style)\b[^>]*>.*?<\s*/\s*\1\s*>#is', '', $svg);
        $svg = preg_replace('#<\s*(script|foreignObject|iframe|embed|object|style)\b[^>]*/?>#is', '', $svg);

        // Strip event-handler attributes: on...="..." / on...='...' / on...=value.
        $svg = preg_replace('#\son[a-z0-9_-]+\s*=\s*"(?:[^"]*)"#is', '', $svg);
        $svg = preg_replace("#\son[a-z0-9_-]+\s*=\s*'(?:[^']*)'#is", '', $svg);
        $svg = preg_replace('#\son[a-z0-9_-]+\s*=\s*[^\s>]+#is', '', $svg);

        // Neutralise javascript: URIs in any href / xlink:href.
        $svg = preg_replace('#((?:xlink:)?href)\s*=\s*([\'"])\s*javascript:[^\'"]*\2#is', '$1=$2#$2', $svg);

        return trim($svg) ?: null;
    }

    /**
     * Normalise admin-pasted icon markup into the stored shape: inner SVG markup
     * plus a viewBox. Staff may paste a complete <svg viewBox="…">…</svg> from any
     * source; we sanitize it, peel off the outer <svg> wrapper, and lift its
     * viewBox out so GameIcon (which supplies its own <svg> + fill) can render it.
     * Pasting bare <path>/<g> markup also works — the viewBox is then left null so
     * the caller keeps the existing/default value.
     *
     * @return array{icon_svg:?string, icon_viewbox:?string}
     */
    public static function normalizeIcon(?string $raw): array
    {
        $clean = self::clean($raw);

        if ($clean === null) {
            return ['icon_svg' => null, 'icon_viewbox' => null];
        }

        if (! preg_match('#<\s*svg\b([^>]*)>(.*)</\s*svg\s*>#is', $clean, $m)) {
            // Bare inner markup — store as-is, leave viewBox for the caller.
            return ['icon_svg' => $clean, 'icon_viewbox' => null];
        }

        [$attrs, $inner] = [$m[1], trim($m[2])];

        $viewBox = null;
        if (preg_match('#viewBox\s*=\s*["\']([^"\']+)["\']#i', $attrs, $vb)) {
            $viewBox = trim($vb[1]);
        } elseif (
            preg_match('#\bwidth\s*=\s*["\']?\s*([0-9.]+)#i', $attrs, $w)
            && preg_match('#\bheight\s*=\s*["\']?\s*([0-9.]+)#i', $attrs, $h)
        ) {
            $viewBox = "0 0 {$w[1]} {$h[1]}";
        }

        return ['icon_svg' => $inner ?: null, 'icon_viewbox' => $viewBox];
    }
}
