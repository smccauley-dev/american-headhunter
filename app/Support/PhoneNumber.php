<?php

namespace App\Support;

/**
 * US phone-number formatting for display and tel: links. Mirrored on the
 * front-end by resources/js/lib/phone.ts — keep the two in sync.
 */
class PhoneNumber
{
    /**
     * Format a phone number for display as +1 (123) 456-7890.
     *
     * Falls back to the original trimmed string when the input is not a
     * recognisable 10- or 11-digit US number (e.g. an international number or
     * an entry with an extension), so nothing is ever lost.
     */
    public static function format(?string $raw): string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return '';
        }

        $digits = preg_replace('/\D+/', '', $raw);

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) !== 10) {
            return $raw;
        }

        return sprintf(
            '+1 (%s) %s-%s',
            substr($digits, 0, 3),
            substr($digits, 3, 3),
            substr($digits, 6, 4),
        );
    }

    /**
     * Dialable tel: href value. Returns E.164 (+1XXXXXXXXXX) for US numbers;
     * otherwise the raw digits, preserving a leading + when present.
     */
    public static function telHref(?string $raw): string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return '';
        }

        $digits = preg_replace('/\D+/', '', $raw);

        if (strlen($digits) === 10) {
            return '+1' . $digits;
        }
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return '+' . $digits;
        }

        return (str_starts_with($raw, '+') ? '+' : '') . $digits;
    }
}
