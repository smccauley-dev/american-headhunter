<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Casts a PostgreSQL text-array column (TEXT[]) to/from a PHP list of strings.
 *
 * Laravel's built-in 'array' cast only understands JSON columns — it cannot
 * parse the PostgreSQL array literal format (e.g. {landowner,hunter}). Use this
 * cast for TEXT[]/VARCHAR[] columns; use 'array' for JSON/JSONB columns.
 *
 * @implements CastsAttributes<list<string>, list<string>|null>
 */
class PgTextArray implements CastsAttributes
{
    /** {a,b,"c, d"} → ['a', 'b', 'c, d'] */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null || $value === '' || $value === '{}') {
            return [];
        }

        // Drop the surrounding braces, then split on commas that are not inside
        // a double-quoted element.
        $inner = substr($value, 1, -1);
        preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|[^,]+/', $inner, $matches);

        return array_map(function (string $element): string {
            $element = trim($element);
            if (str_starts_with($element, '"') && str_ends_with($element, '"')) {
                return stripcslashes(substr($element, 1, -1));
            }
            return $element;
        }, $matches[0]);
    }

    /** ['a', 'b', 'c, d'] → {a,b,"c, d"} */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $elements = array_map(function ($element): string {
            $element = (string) $element;
            // Quote any element that would otherwise break the array literal.
            if ($element === '' || preg_match('/[{},"\\\\\s]/', $element)) {
                return '"' . addcslashes($element, '"\\') . '"';
            }
            return $element;
        }, (array) $value);

        return '{' . implode(',', $elements) . '}';
    }
}
