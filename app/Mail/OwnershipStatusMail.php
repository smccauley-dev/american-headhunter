<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Queue\SerializesModels;

/**
 * Notifies a landowner of a change in their property's proof-of-ownership review
 * stage (submitted / under review / approved / rejected). One DB-managed template
 * (property.ownership_status) renders every stage — the stage-specific sentence is
 * injected as {status_message}, like application.message injects {message_body}.
 */
class OwnershipStatusMail extends TemplatedMailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $recipientName,
        public readonly string $propertyTitle,
        public readonly string $statusLabel,
        public readonly string $statusMessage,
        public readonly string $propertyUrl,
    ) {}

    protected function templateKey(): string
    {
        return 'property.ownership_status';
    }

    protected function templateVariables(): array
    {
        return [
            'recipient_name' => $this->recipientName,
            'property_title' => $this->propertyTitle,
            'status_label'   => $this->statusLabel,
            'status_message' => $this->statusMessage,
            'property_url'   => $this->propertyUrl,
        ];
    }

    protected function fallbackSubject(): string
    {
        return "Proof of ownership {$this->statusLabel} — American Headhunter";
    }

    protected function fallbackContent(): Content
    {
        return new Content(markdown: 'emails.ownership-status');
    }
}
