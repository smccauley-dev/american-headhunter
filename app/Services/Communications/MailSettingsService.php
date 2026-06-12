<?php

namespace App\Services\Communications;

use App\Services\BaseService;
use App\Services\Platform\TenantService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;

/**
 * DB-managed outbound mail configuration (DB 12 tenant_settings). When
 * enabled, these settings override the .env mail config at runtime so the
 * transport can be changed from the admin panel without a deploy. The SMTP
 * password is encrypted before storage and is never returned to the UI.
 */
class MailSettingsService extends BaseService
{
    /** Provider presets — host/port/encryption pre-fills for the admin form. */
    public const PRESETS = [
        'custom'    => ['label' => 'Custom SMTP',                'host' => '',                              'port' => 587, 'encryption' => 'tls'],
        'google'    => ['label' => 'Google Workspace / Gmail',   'host' => 'smtp.gmail.com',                'port' => 587, 'encryption' => 'tls'],
        'microsoft' => ['label' => 'Microsoft 365 / Exchange',   'host' => 'smtp.office365.com',            'port' => 587, 'encryption' => 'tls'],
        'ses'       => ['label' => 'AWS SES',                    'host' => 'email-smtp.us-east-1.amazonaws.com', 'port' => 587, 'encryption' => 'tls'],
        'mailchimp' => ['label' => 'Mailchimp Transactional',    'host' => 'smtp.mandrillapp.com',          'port' => 587, 'encryption' => 'tls'],
        'sendgrid'  => ['label' => 'SendGrid',                   'host' => 'smtp.sendgrid.net',             'port' => 587, 'encryption' => 'tls'],
    ];

    public function __construct(
        private readonly TenantService $tenantService,
    ) {}

    /** Settings for the admin form. The password is never included — only whether one is stored. */
    public function getSettings(): array
    {
        $t = $this->tenantService;

        return [
            'enabled'      => (bool) $t->getSetting('mail.enabled', false),
            'preset'       => $t->getSetting('mail.preset', 'custom'),
            'host'         => $t->getSetting('mail.host', ''),
            'port'         => (int) $t->getSetting('mail.port', 587),
            'encryption'   => $t->getSetting('mail.encryption', 'tls'),
            'username'     => $t->getSetting('mail.username', ''),
            'has_password' => $t->getSetting('mail.password_encrypted') !== null,
            'from_address' => $t->getSetting('mail.from_address', ''),
            'from_name'    => $t->getSetting('mail.from_name', ''),
        ];
    }

    /**
     * Persist settings from the admin form. A blank password keeps the
     * stored one; a non-blank value replaces it (encrypted at rest).
     */
    public function saveSettings(array $settings): void
    {
        $t = $this->tenantService;

        $t->setSetting('mail.enabled',      (bool) $settings['enabled'],              'Use DB-managed mail settings instead of .env');
        $t->setSetting('mail.preset',       $settings['preset'] ?? 'custom',          'Mail provider preset selected in admin');
        $t->setSetting('mail.host',         trim($settings['host'] ?? ''),            'SMTP host');
        $t->setSetting('mail.port',         (int) ($settings['port'] ?? 587),         'SMTP port');
        $t->setSetting('mail.encryption',   $settings['encryption'] ?? 'tls',         'SMTP encryption: tls, ssl or none');
        $t->setSetting('mail.username',     trim($settings['username'] ?? ''),        'SMTP username');
        $t->setSetting('mail.from_address', trim($settings['from_address'] ?? ''),    'Default From address');
        $t->setSetting('mail.from_name',    trim($settings['from_name'] ?? ''),       'Default From name');

        if (($settings['password'] ?? '') !== '') {
            $t->setSetting('mail.password_encrypted', Crypt::encryptString($settings['password']), 'SMTP password (encrypted)');
        }
    }

    public function clearPassword(): void
    {
        $this->tenantService->setSetting('mail.password_encrypted', null);
    }

    /**
     * Override Laravel's mail config with the DB-managed settings. Called on
     * boot; a failure (e.g. platform DB unavailable during migrations) leaves
     * the .env config in place.
     */
    public function apply(): void
    {
        $settings = $this->getSettings();

        if (! $settings['enabled'] || $settings['host'] === '') {
            return;
        }

        $password = null;
        $encrypted = $this->tenantService->getSetting('mail.password_encrypted');
        if ($encrypted !== null) {
            $password = Crypt::decryptString($encrypted);
        }

        config([
            'mail.default'                  => 'smtp',
            'mail.mailers.smtp.host'        => $settings['host'],
            'mail.mailers.smtp.port'        => $settings['port'],
            'mail.mailers.smtp.encryption'  => $settings['encryption'] === 'none' ? null : $settings['encryption'],
            'mail.mailers.smtp.username'    => $settings['username'] !== '' ? $settings['username'] : null,
            'mail.mailers.smtp.password'    => $password,
        ]);

        if ($settings['from_address'] !== '') {
            config([
                'mail.from.address' => $settings['from_address'],
                'mail.from.name'    => $settings['from_name'] !== '' ? $settings['from_name'] : config('mail.from.name'),
            ]);
        }

        // Drop any mailer Laravel already resolved with the old config.
        Mail::purge('smtp');
    }

    /** Send a plain test email through the currently saved settings. Throws on failure. */
    public function sendTest(string $toAddress): void
    {
        $this->apply();

        Mail::raw(
            "This is a test email from American Headhunter.\n\n"
            . 'If you received this, the outbound mail settings are working. '
            . 'Sent ' . now()->toDayDateTimeString() . '.',
            function ($message) use ($toAddress): void {
                $message->to($toAddress)->subject('American Headhunter — Test Email');
            },
        );
    }
}
