<?php

namespace App\Mail\Auth;

use App\Mail\TemplatedMailable;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Queue\SerializesModels;

class EmailVerificationMail extends TemplatedMailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $firstName,
        public readonly string $verificationUrl,
    ) {}

    protected function templateKey(): string
    {
        return 'auth.verify_email';
    }

    protected function templateVariables(): array
    {
        return [
            'first_name'       => $this->firstName !== '' ? $this->firstName : 'there',
            'verification_url' => $this->verificationUrl,
        ];
    }

    protected function fallbackSubject(): string
    {
        return 'Verify Your Email — American Headhunter';
    }

    protected function fallbackContent(): Content
    {
        return new Content(view: 'emails.auth.verify-email');
    }
}
