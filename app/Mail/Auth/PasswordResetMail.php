<?php

namespace App\Mail\Auth;

use App\Mail\TemplatedMailable;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Queue\SerializesModels;

class PasswordResetMail extends TemplatedMailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $firstName,
        public readonly string $resetUrl,
    ) {}

    protected function templateKey(): string
    {
        return 'auth.password_reset';
    }

    protected function templateVariables(): array
    {
        return [
            'first_name' => $this->firstName !== '' ? $this->firstName : 'there',
            'reset_url'  => $this->resetUrl,
        ];
    }

    protected function fallbackSubject(): string
    {
        return 'Reset Your Password — American Headhunter';
    }

    protected function fallbackContent(): Content
    {
        return new Content(view: 'emails.auth.password-reset');
    }
}
