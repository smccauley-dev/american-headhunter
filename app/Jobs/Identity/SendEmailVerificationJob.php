<?php

namespace App\Jobs\Identity;

use App\Mail\Auth\EmailVerificationMail;
use App\Models\Identity\User;
use App\Services\Identity\VerificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendEmailVerificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly string $userId) {}

    public function handle(VerificationService $verification): void
    {
        $user = User::find($this->userId);

        if (! $user || $user->email_verified_at) {
            return;
        }

        $token = $verification->createEmailToken($user);

        $url = route('auth.verify-email', ['token' => $token, 'id' => $user->id]);

        $firstName = $user->profile?->first_name ?? '';

        Mail::to($user->email)->send(new EmailVerificationMail($firstName, $url));
    }
}
