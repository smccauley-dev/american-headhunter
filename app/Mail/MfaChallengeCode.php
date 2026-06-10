<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class MfaChallengeCode extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $code,
        public readonly int    $expiresMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your American Headhunter verification code');
    }

    public function content(): Content
    {
        return new Content(text: 'emails.mfa-code');
    }
}
