<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Queue\SerializesModels;

class ApplicationMessageMail extends TemplatedMailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $recipientName,
        public readonly string $senderRoleLabel,
        public readonly string $messageBody,
        public readonly string $applicationId,
        public readonly string $loginUrl,
    ) {}

    protected function templateKey(): string
    {
        return 'application.message';
    }

    protected function templateVariables(): array
    {
        return [
            'recipient_name'  => $this->recipientName,
            'sender_role'     => $this->senderRoleLabel,
            'message_body'    => $this->messageBody,
            'application_ref' => strtoupper(substr($this->applicationId, 0, 8)),
            'login_url'       => $this->loginUrl,
        ];
    }

    protected function fallbackSubject(): string
    {
        return 'New message regarding your application — American Headhunter';
    }

    protected function fallbackContent(): Content
    {
        return new Content(markdown: 'emails.application-message');
    }
}
