<?php

namespace App\Services\Sms;

use App\Contracts\Sms\SmsDriver;
use Illuminate\Support\Facades\Log;

class StubSmsDriver implements SmsDriver
{
    public function send(string $phone, string $message): void
    {
        Log::channel('single')->info("[StubSmsDriver] SMS to {$phone}: {$message}");
    }
}
