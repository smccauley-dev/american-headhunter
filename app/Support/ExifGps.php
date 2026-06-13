<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

class ExifGps
{
    /**
     * Pull GPS coordinates out of an image's EXIF block (JPEG/TIFF only).
     * Returns [null, null] when the camera didn't record a location.
     *
     * @return array{0: ?float, 1: ?float}  [latitude, longitude]
     */
    public static function extract(UploadedFile $file): array
    {
        if (! function_exists('exif_read_data')
            || ! in_array($file->getMimeType(), ['image/jpeg', 'image/tiff'], true)) {
            return [null, null];
        }

        try {
            $exif = @exif_read_data($file->getRealPath());
        } catch (\Throwable) {
            return [null, null];
        }

        if (empty($exif['GPSLatitude']) || empty($exif['GPSLongitude'])) {
            return [null, null];
        }

        $lat = self::toDecimal((array) $exif['GPSLatitude'], $exif['GPSLatitudeRef'] ?? 'N');
        $lng = self::toDecimal((array) $exif['GPSLongitude'], $exif['GPSLongitudeRef'] ?? 'E');

        if ($lat === null || $lng === null
            || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180
            || ($lat === 0.0 && $lng === 0.0)) {
            return [null, null];
        }

        return [round($lat, 6), round($lng, 6)];
    }

    /** Convert EXIF degrees/minutes/seconds rationals ("123/1") to decimal degrees. */
    private static function toDecimal(array $parts, string $ref): ?float
    {
        $toFloat = function ($v): float {
            $v = (string) $v;
            if (str_contains($v, '/')) {
                [$num, $den] = explode('/', $v, 2);
                return (float) $den != 0.0 ? (float) $num / (float) $den : 0.0;
            }
            return (float) $v;
        };

        if (! isset($parts[0])) {
            return null;
        }

        $decimal = $toFloat($parts[0])
            + $toFloat($parts[1] ?? 0) / 60
            + $toFloat($parts[2] ?? 0) / 3600;

        return in_array(strtoupper($ref), ['S', 'W'], true) ? -$decimal : $decimal;
    }
}
