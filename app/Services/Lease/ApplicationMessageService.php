<?php

namespace App\Services\Lease;

use App\Jobs\SendApplicationMessageEmail;
use App\Models\Lease\LeaseApplication;
use App\Models\Lease\LeaseApplicationMessage;
use App\Services\BaseService;
use App\Services\Identity\UserService;
use App\Services\Property\PropertyService;
use Illuminate\Support\Collection;

class ApplicationMessageService extends BaseService
{
    public function __construct(
        private readonly UserService     $userService,
        private readonly PropertyService $propertyService,
    ) {}

    public function send(
        string $applicationId,
        string $senderUserId,
        string $senderRole,
        string $message
    ): LeaseApplicationMessage {
        if (! in_array($senderRole, ['admin', 'landowner', 'applicant'], true)) {
            throw new \InvalidArgumentException("Invalid sender role '{$senderRole}'.");
        }

        $msg = LeaseApplicationMessage::create([
            'application_id' => $applicationId,
            'sender_user_id' => $senderUserId,
            'sender_role'    => $senderRole,
            'message'        => $message,
        ]);

        $application = LeaseApplication::find($applicationId);
        if ($application) {
            $this->queueEmailNotification($msg, $application);
        }

        return $msg;
    }

    public function getForApplication(string $applicationId): Collection
    {
        return LeaseApplicationMessage::where('application_id', $applicationId)
            ->orderBy('created_at')
            ->get();
    }

    public function markRead(string $applicationId, string $readerUserId): void
    {
        LeaseApplicationMessage::where('application_id', $applicationId)
            ->where('sender_user_id', '!=', $readerUserId)
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);
    }

    public function saveNotes(string $applicationId, string $notes): void
    {
        LeaseApplication::where('id', $applicationId)
            ->update(['admin_notes' => $notes ?: null]);
    }

    public function unreadCount(string $applicationId, string $userId): int
    {
        return LeaseApplicationMessage::where('application_id', $applicationId)
            ->where('sender_user_id', '!=', $userId)
            ->where('is_read', false)
            ->count();
    }

    private function queueEmailNotification(
        LeaseApplicationMessage $msg,
        LeaseApplication $application
    ): void {
        if (in_array($msg->sender_role, ['admin', 'landowner'])) {
            // Notify applicant
            $recipient = $this->userService->findById($application->applicant_user_id);
            if ($recipient?->email) {
                $name = $recipient->profile
                    ? trim("{$recipient->profile->first_name} {$recipient->profile->last_name}")
                    : 'Applicant';
                SendApplicationMessageEmail::dispatch(
                    $msg->id,
                    $recipient->email,
                    $name ?: 'Applicant',
                    'applicant',
                    $application->id,
                );
            }
        }

        if ($msg->sender_role === 'applicant') {
            // Notify landowner via listing → property → owner
            $listing = $this->propertyService->findListing($application->listing_id);
            $ownerUserId = $listing?->property?->owner_user_id ?? null;
            if ($ownerUserId) {
                $owner = $this->userService->findById($ownerUserId);
                if ($owner?->email) {
                    $name = $owner->profile
                        ? trim("{$owner->profile->first_name} {$owner->profile->last_name}")
                        : 'Landowner';
                    SendApplicationMessageEmail::dispatch(
                        $msg->id,
                        $owner->email,
                        $name ?: 'Landowner',
                        'landowner',
                        $application->id,
                    );
                }
            }
        }
    }
}
