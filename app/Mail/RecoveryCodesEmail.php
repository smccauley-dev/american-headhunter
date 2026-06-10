<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class RecoveryCodesEmail extends Mailable
{
    use Queueable;

    public function __construct(public readonly array $codes) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your new American Headhunter recovery codes');
    }

    public function content(): Content
    {
        return new Content(text: 'emails.recovery-codes');
    }
}
