<?php

namespace App\Contracts\Sms;

interface SmsDriver
{
    public function send(string $phone, string $message): void;
}
