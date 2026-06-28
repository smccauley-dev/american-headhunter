<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Queue\SerializesModels;

/**
 * Tells a member their account has been paused because a 'pause_account'
 * promotional period lapsed, and links them to the reactivation page where a
 * paid subscription lifts the pause. Renders from the DB-managed template
 * (billing.account_paused) with a Blade fallback.
 */
class AccountPausedMail extends TemplatedMailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $recipientName,
        public readonly string $reactivateUrl,
    ) {}

    protected function templateKey(): string
    {
        return 'billing.account_paused';
    }

    protected function templateVariables(): array
    {
        return [
            'recipient_name' => $this->recipientName,
            'reactivate_url' => $this->reactivateUrl,
        ];
    }

    protected function fallbackSubject(): string
    {
        return 'Your American Headhunter account is paused';
    }

    protected function fallbackContent(): Content
    {
        return new Content(markdown: 'emails.account-paused');
    }
}
