<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ApplicationMessageMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $recipientName,
        public readonly string $senderRoleLabel,
        public readonly string $messageBody,
        public readonly string $applicationId,
        public readonly string $loginUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New message regarding your application — American Headhunter',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.application-message',
        );
    }
}
