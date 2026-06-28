<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Queue\SerializesModels;

/**
 * Warns a member that their promotional period is ending. One DB-managed template
 * (billing.promotion_expiring) renders every warning window (30 / 7 / 1 day) — the
 * window-specific copy is injected as {status_message}, mirroring how
 * property.ownership_status injects {status_message}. The copy also reflects what
 * happens at expiry (convert to paid, downgrade to free, or pause).
 */
class PromotionExpiringMail extends TemplatedMailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $recipientName,
        public readonly string $whenLabel,      // "in 30 days" | "in 7 days" | "tomorrow"
        public readonly string $statusMessage,
        public readonly string $manageUrl,
    ) {}

    protected function templateKey(): string
    {
        return 'billing.promotion_expiring';
    }

    protected function templateVariables(): array
    {
        return [
            'recipient_name' => $this->recipientName,
            'when_label'     => $this->whenLabel,
            'status_message' => $this->statusMessage,
            'manage_url'     => $this->manageUrl,
        ];
    }

    protected function fallbackSubject(): string
    {
        return "Your American Headhunter promotion ends {$this->whenLabel}";
    }

    protected function fallbackContent(): Content
    {
        return new Content(markdown: 'emails.promotion-expiring');
    }
}
