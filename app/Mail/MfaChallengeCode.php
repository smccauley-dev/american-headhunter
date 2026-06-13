<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;

class MfaChallengeCode extends TemplatedMailable
{
    use Queueable;

    public function __construct(
        public readonly string $code,
        public readonly int    $expiresMinutes,
    ) {}

    protected function templateKey(): string
    {
        return 'auth.mfa_code';
    }

    protected function templateVariables(): array
    {
        return [
            'code'            => $this->code,
            'expires_minutes' => (string) $this->expiresMinutes,
        ];
    }

    protected function fallbackSubject(): string
    {
        return 'Your American Headhunter verification code';
    }

    protected function fallbackContent(): Content
    {
        return new Content(text: 'emails.mfa-code');
    }
}
