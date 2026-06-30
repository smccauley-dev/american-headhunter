<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Queue\SerializesModels;

/**
 * Notifies the hunter that the landowner decided their early-termination request
 * (approved or denied). One DB-managed template (lease.termination_decision)
 * renders both outcomes — the outcome-specific sentence and any refund summary
 * are injected as {status_message} / {refund_summary}, like other status emails.
 */
class LeaseTerminationDecisionMail extends TemplatedMailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $recipientName,
        public readonly string $propertyTitle,
        public readonly string $statusLabel,
        public readonly string $statusMessage,
        public readonly string $refundSummary,
        public readonly string $leaseUrl,
    ) {}

    protected function templateKey(): string
    {
        return 'lease.termination_decision';
    }

    protected function templateVariables(): array
    {
        return [
            'recipient_name' => $this->recipientName,
            'property_title' => $this->propertyTitle,
            'status_label'   => $this->statusLabel,
            'status_message' => $this->statusMessage,
            'refund_summary' => $this->refundSummary,
            'lease_url'      => $this->leaseUrl,
        ];
    }

    protected function fallbackSubject(): string
    {
        return "Early termination {$this->statusLabel} — American Headhunter";
    }

    protected function fallbackContent(): Content
    {
        return new Content(markdown: 'emails.lease-termination-decision');
    }
}
