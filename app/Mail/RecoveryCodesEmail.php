<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;

class RecoveryCodesEmail extends TemplatedMailable
{
    use Queueable;

    public function __construct(public readonly array $codes) {}

    protected function templateKey(): string
    {
        return 'auth.recovery_codes';
    }

    protected function templateVariables(): array
    {
        return [
            'codes' => $this->codes,
        ];
    }

    protected function fallbackSubject(): string
    {
        return 'Your new American Headhunter recovery codes';
    }

    protected function fallbackContent(): Content
    {
        return new Content(text: 'emails.recovery-codes');
    }
}
