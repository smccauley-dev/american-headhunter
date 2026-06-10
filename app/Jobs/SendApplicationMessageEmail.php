<?php

namespace App\Jobs;

use App\Mail\ApplicationMessageMail;
use App\Models\Lease\LeaseApplicationMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendApplicationMessageEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        private readonly string $messageId,
        private readonly string $recipientEmail,
        private readonly string $recipientName,
        private readonly string $recipientRole,
        private readonly string $applicationId,
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $msg = LeaseApplicationMessage::find($this->messageId);
        if (! $msg) {
            return;
        }

        $senderLabel = match ($msg->sender_role) {
            'admin'     => 'American Headhunter Team',
            'landowner' => 'The Landowner',
            'applicant' => 'The Applicant',
            default     => 'Someone',
        };

        $loginUrl = url('/login');

        Mail::to($this->recipientEmail)->send(
            new ApplicationMessageMail(
                recipientName:   $this->recipientName,
                senderRoleLabel: $senderLabel,
                messageBody:     $msg->message,
                applicationId:   $this->applicationId,
                loginUrl:        $loginUrl,
            )
        );
    }
}
