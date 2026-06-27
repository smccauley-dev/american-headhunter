<?php

namespace App\Jobs\Property;

use App\Mail\OwnershipStatusMail;
use App\Models\Property\Property;
use App\Services\Identity\UserService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * Emails the landowner who submitted proof of ownership when its review stage
 * changes. The intended stage is captured at dispatch ($status) so the email
 * always reflects the transition that triggered it, even if the record moves on
 * before the queue runs. Cross-DB data (DB 1 user, DB 2 property) is assembled
 * here in PHP — never via Eloquent relations.
 */
class SendOwnershipStatusEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $propertyId,
        public readonly string $submitterUserId,
        public readonly string $status,          // submitted | pending | approved | rejected
        public readonly ?string $reviewNotes = null,
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $user = app(UserService::class)->findById($this->submitterUserId);
        if (! $user || ! $user->email) {
            return;
        }

        $property = Property::on('property_read')
            ->whereNull('deleted_at')
            ->find($this->propertyId);
        $title = $property?->title ?: 'your property';

        [$label, $message] = $this->copy($title);

        Mail::to($user->email)->send(new OwnershipStatusMail(
            recipientName: $user->getFilamentName(),
            propertyTitle: $title,
            statusLabel:   $label,
            statusMessage: $message,
            propertyUrl:   url("/member/properties/{$this->propertyId}"),
        ));
    }

    /** @return array{0:string,1:string} [status label, status-specific message] */
    private function copy(string $title): array
    {
        return match ($this->status) {
            'submitted' => [
                'Submitted',
                "We've received your proof of ownership for \"{$title}\" and added it to our review queue. "
                    . "We'll email you again as soon as a reviewer has looked it over — there's nothing you need to do right now.",
            ],
            'pending' => [
                'Under Review',
                "Your proof of ownership for \"{$title}\" is now being actively reviewed by our team. "
                    . "We'll let you know the moment a decision is made.",
            ],
            'approved' => [
                'Approved',
                "Good news — your proof of ownership for \"{$title}\" has been approved. "
                    . "Your property is now verified and can be published and taken live.",
            ],
            'rejected' => [
                'Rejected',
                trim(
                    "We were unable to verify your proof of ownership for \"{$title}\"."
                    . ($this->reviewNotes ? " Reason: {$this->reviewNotes}" : '')
                    . " You can upload updated documents from your property page and resubmit."
                ),
            ],
            default => [
                'Updated',
                "There's an update on your proof of ownership for \"{$title}\".",
            ],
        };
    }
}
