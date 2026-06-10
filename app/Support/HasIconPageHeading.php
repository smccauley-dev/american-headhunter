<?php

namespace App\Support;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

/**
 * Add this trait to any Filament Page or ListRecords class to render an icon
 * inline with the page heading. Call $this->headingWithIcon('Label', 'heroicon-o-name').
 *
 * Convention: all admin pages use this trait and match their $navigationIcon.
 */
trait HasIconPageHeading
{
    protected function headingWithIcon(string $text, string $icon): HtmlString
    {
        $svg = Blade::render('<x-filament::icon :icon="$icon" />', ['icon' => $icon]);

        // Inject size + color directly on the <svg> tag so Tailwind utility classes can't override it
        $svg = preg_replace(
            '/<svg/',
            '<svg style="width:2rem;height:2rem;flex-shrink:0;color:#c84c21;stroke:#c84c21;"',
            $svg,
            1
        );

        return new HtmlString(
            '<span style="display:inline-flex;align-items:center;gap:0.35em;">' .
                $svg .
                e($text) .
            '</span>'
        );
    }
}
