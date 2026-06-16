<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;

/**
 * Out-of-band notice that an administrator enabled a two-factor method on the
 * account. Tells the holder their next login now needs a second factor and, if
 * they cannot complete it (e.g. they removed their authenticator), how to
 * recover. Sent for any admin-initiated factor enable.
 */
class MfaFactorEnabledByAdminMail extends TemplatedMailable
{
    use Queueable;

    public function __construct(public readonly string $methodLabel) {}

    protected function templateKey(): string
    {
        return 'auth.mfa_enabled_by_admin';
    }

    protected function templateVariables(): array
    {
        return [
            'method_label' => $this->methodLabel,
        ];
    }

    protected function fallbackSubject(): string
    {
        return 'A two-factor method was enabled on your American Headhunter account';
    }

    protected function fallbackContent(): Content
    {
        return new Content(text: 'emails.mfa-enabled-by-admin');
    }
}
