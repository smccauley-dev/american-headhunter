<?php

namespace App\Services\Documents;

use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

/**
 * Renders QR code images on the fly. Stateless — no storage. Callers encode a
 * URL (e.g. the public check-in landing) and get raw PNG bytes back, suitable
 * for an image response, an <img> tag, or an email attachment.
 */
class QrImageService
{
    public function png(string $content, int $size = 320): string
    {
        $qr = new QrCode(
            data: $content,
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: $size,
            margin: 16,
        );

        return (new PngWriter())->write($qr)->getString();
    }
}
