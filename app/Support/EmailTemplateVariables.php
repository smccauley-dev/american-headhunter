<?php

namespace App\Support;

/**
 * Variable catalog for email templates. Each template key declares the
 * placeholders it supports plus a sample value used for previews and
 * test sends. Placeholders use {snake_case} token syntax.
 */
class EmailTemplateVariables
{
    /** Variables available to every template. */
    public const GLOBAL = [
        'platform_name' => ['label' => 'Platform name',        'sample' => 'American Headhunter'],
        'support_email' => ['label' => 'Support email address', 'sample' => 'support@americanheadhunter.com'],
        'app_url'       => ['label' => 'Platform base URL',     'sample' => 'https://americanheadhunter.com'],
        'current_year'  => ['label' => 'Current year',          'sample' => '2026'],
    ];

    /** Per-template-key variables. Keys not listed here support only the globals. */
    public const CATALOG = [
        'auth.verify_email' => [
            'first_name'       => ['label' => "Recipient's first name",      'sample' => 'John'],
            'verification_url' => ['label' => 'Email verification link',     'sample' => 'https://americanheadhunter.com/verify-email/sample-token'],
        ],
        'auth.password_reset' => [
            'first_name' => ['label' => "Recipient's first name", 'sample' => 'John'],
            'reset_url'  => ['label' => 'Password reset link',    'sample' => 'https://americanheadhunter.com/reset-password/sample-token'],
        ],
        'auth.mfa_code' => [
            'code'            => ['label' => 'One-time verification code', 'sample' => '482917'],
            'expires_minutes' => ['label' => 'Code lifetime in minutes',   'sample' => '10'],
        ],
        'auth.recovery_codes' => [
            'codes' => ['label' => 'Recovery codes (one per line)', 'sample' => "AAAA-1111\nBBBB-2222\nCCCC-3333"],
        ],
        'application.message' => [
            'recipient_name'  => ['label' => "Recipient's name",                 'sample' => 'John'],
            'sender_role'     => ['label' => 'Sender role label',                'sample' => 'Property Owner'],
            'message_body'    => ['label' => 'The message text',                 'sample' => 'Thanks for your application — we would like to schedule a call.'],
            'application_ref' => ['label' => 'Short application reference',      'sample' => 'A1B2C3D4'],
            'login_url'       => ['label' => 'Link to log in and reply',         'sample' => 'https://americanheadhunter.com/apply/login'],
        ],
    ];

    /** All variables (template-specific + global) for a template key. */
    public static function for(string $templateKey): array
    {
        return array_merge(self::GLOBAL, self::CATALOG[$templateKey] ?? []);
    }

    /** Sample values keyed by variable name, for previews and test sends. */
    public static function samplesFor(string $templateKey): array
    {
        return array_map(fn (array $v) => $v['sample'], self::for($templateKey));
    }

    /** Extract {placeholder} tokens used in a string. */
    public static function extract(string $content): array
    {
        preg_match_all('/\{([a-z0-9_]+)\}/', $content, $matches);

        return array_values(array_unique($matches[1]));
    }
}
