<?php

namespace App\Jobs\Identity;

use App\Mail\Auth\PasswordResetMail;
use App\Models\Identity\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendPasswordResetJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $userId,
        public readonly string $plainToken,
    ) {}

    public function handle(): void
    {
        $user = User::find($this->userId);

        if (! $user) {
            return;
        }

        $url = route('auth.password.reset', ['token' => $this->plainToken, 'email' => $user->email]);

        $firstName = $user->profile?->first_name ?? '';

        Mail::to($user->email)->send(new PasswordResetMail($firstName, $url));
    }
}
