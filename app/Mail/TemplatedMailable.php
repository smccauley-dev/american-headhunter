<?php

namespace App\Mail;

use App\DTOs\RenderedEmail;
use App\Services\Communications\EmailTemplateService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Log;

/**
 * Mailable that renders from a DB-managed email template (DB 7), falling
 * back to its original hardcoded Blade view when no active template exists
 * or template rendering fails. Auth-critical email must never stop sending
 * because of a template problem.
 */
abstract class TemplatedMailable extends Mailable
{
    private ?RenderedEmail $renderedTemplate = null;
    private bool $renderAttempted = false;

    /** The email_templates.template_key this mailable renders from. */
    abstract protected function templateKey(): string;

    /** Placeholder values passed to the template renderer. */
    abstract protected function templateVariables(): array;

    /** Subject used when no active template exists. */
    abstract protected function fallbackSubject(): string;

    /** Original Blade content used when no active template exists. */
    abstract protected function fallbackContent(): Content;

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->rendered()?->subject ?? $this->fallbackSubject());
    }

    public function content(): Content
    {
        $rendered = $this->rendered();

        if ($rendered === null) {
            return $this->fallbackContent();
        }

        if ($rendered->html !== null) {
            return new Content(
                htmlString: $rendered->html,
                text: $rendered->text !== null ? 'emails.rendered-text' : null,
                with: ['body' => $rendered->text],
            );
        }

        return new Content(
            text: 'emails.rendered-text',
            with: ['body' => $rendered->text],
        );
    }

    private function rendered(): ?RenderedEmail
    {
        if (! $this->renderAttempted) {
            $this->renderAttempted = true;

            try {
                $this->renderedTemplate = app(EmailTemplateService::class)
                    ->render($this->templateKey(), $this->templateVariables());
            } catch (\Throwable $e) {
                Log::warning('Email template render failed, using Blade fallback', [
                    'template_key' => $this->templateKey(),
                    'error'        => $e->getMessage(),
                ]);
            }
        }

        return $this->renderedTemplate;
    }
}
