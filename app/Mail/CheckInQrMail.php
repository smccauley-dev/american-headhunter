<?php

namespace App\Mail;

use App\Services\Documents\QrImageService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Queue\SerializesModels;

class CheckInQrMail extends TemplatedMailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $recipientName,
        public readonly string $propertyTitle,
        public readonly string $scanUrl,
    ) {}

    protected function templateKey(): string
    {
        return 'lease.check_in_qr';
    }

    protected function templateVariables(): array
    {
        return [
            'recipient_name' => $this->recipientName,
            'property_title' => $this->propertyTitle,
            'scan_url'       => $this->scanUrl,
        ];
    }

    protected function fallbackSubject(): string
    {
        return "Your check-in QR code — {$this->propertyTitle}";
    }

    protected function fallbackContent(): Content
    {
        return new Content(markdown: 'emails.check-in-qr', with: [
            'recipientName' => $this->recipientName,
            'propertyTitle' => $this->propertyTitle,
            'scanUrl'       => $this->scanUrl,
        ]);
    }

    /** Attach the QR as a PNG so it survives even when remote images are blocked. */
    public function attachments(): array
    {
        $png = app(QrImageService::class)->png($this->scanUrl, 480);

        return [
            Attachment::fromData(fn () => $png, 'check-in-qr.png')->withMime('image/png'),
        ];
    }
}
