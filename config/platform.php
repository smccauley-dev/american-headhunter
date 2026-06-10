<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Legal URLs
    |--------------------------------------------------------------------------
    |
    | URLs for Terms of Service, Privacy Policy, and CCPA Notice pages.
    | These are passed to frontend pages as Inertia props.
    | In Phase 3 these will be overridden by DB 12 tenant_settings.
    |
    */

    'legal' => [
        'tos_url'     => env('LEGAL_TOS_URL',     '/terms'),
        'privacy_url' => env('LEGAL_PRIVACY_URL', '/privacy'),
        'ccpa_url'    => env('LEGAL_CCPA_URL',    '/privacy#ccpa'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Post-Logout Redirect URL
    |--------------------------------------------------------------------------
    |
    | Where users are sent after signing out. Defaults to the home page.
    | Set LOGOUT_REDIRECT_URL in .env, or override per-tenant in Phase 3
    | via DB 12 tenant_settings (key: logout_redirect_url).
    |
    */

    'logout_redirect_url' => env('LOGOUT_REDIRECT_URL', '/'),

    /*
    |--------------------------------------------------------------------------
    | Admin IP Bypass
    |--------------------------------------------------------------------------
    |
    | Server-level emergency bypass for the admin IP allowlist.
    | Set ADMIN_IP_BYPASS_IP in .env. Must be read via config() not env()
    | so it survives php artisan config:cache in production.
    |
    */

    'admin_ip_bypass_ip' => env('ADMIN_IP_BYPASS_IP'),

];
