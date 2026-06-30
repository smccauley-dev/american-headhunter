<?php

namespace App\Jobs\Lease;

use App\Mail\LeaseTerminationDecisionMail;
use App\Models\Lease\Lease;
use App\Models\Property\Property;
use App\Services\Identity\UserService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * Emails the hunter (lessee) when the landowner approves or denies their
 * early-termination request. The outcome and refund amounts are captured at
 * dispatch so the email reflects the decision even if records move on before the
 * queue runs. Cross-DB data (DB 1 user, DB 2 property, DB 3 lease) is assembled
 * here in PHP — never via Eloquent relations. No payment-instrument data is sent
 * (only the refunded dollar totals the hunter is owed).
 */
class SendLeaseTerminationDecisionEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $leaseId,
        public readonly string $recipientUserId,
        public readonly string $decision,          // approved | denied
        public readonly int $depositRefundedCents = 0,
        public readonly int $rentRefundedCents = 0,
        public readonly ?string $note = null,
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $user = app(UserService::class)->findById($this->recipientUserId);
        if (! $user || ! $user->email) {
            return;
        }

        $lease = Lease::find($this->leaseId);
        $title = 'your lease';
        if ($lease) {
            $property = Property::on('property_read')->whereNull('deleted_at')->find($lease->property_id);
            $title    = $property?->title ?: $title;
        }

        [$label, $message] = $this->copy($title);

        Mail::to($user->email)->send(new LeaseTerminationDecisionMail(
            recipientName: $user->getFilamentName(),
            propertyTitle: $title,
            statusLabel:   $label,
            statusMessage: $message,
            refundSummary: $this->decision === 'approved' ? $this->refundSummary() : '',
            leaseUrl:      url("/member/leases/{$this->leaseId}"),
        ));
    }

    /** @return array{0:string,1:string} [status label, outcome-specific message] */
    private function copy(string $title): array
    {
        if ($this->decision === 'approved') {
            return [
                'Approved',
                trim(
                    "Your request to end your lease for \"{$title}\" early has been approved, and the lease is now terminated."
                    . ($this->note ? " The landowner added: \"{$this->note}\"" : '')
                ),
            ];
        }

        return [
            'Denied',
            trim(
                "Your request to end your lease for \"{$title}\" early was not approved — the lease remains active."
                . ($this->note ? " The landowner added: \"{$this->note}\"" : '')
                . ' You can submit a new request from your lease page.'
            ),
        ];
    }

    /** Plain-language summary of what was returned to the hunter's card. */
    private function refundSummary(): string
    {
        $parts = [];
        if ($this->rentRefundedCents > 0) {
            $parts[] = '$' . number_format($this->rentRefundedCents / 100, 2) . ' of prepaid rent';
        }
        if ($this->depositRefundedCents > 0) {
            $parts[] = '$' . number_format($this->depositRefundedCents / 100, 2) . ' of your security deposit';
        }

        if (! $parts) {
            return 'No refund was issued — your security deposit was forfeited as the early-exit penalty.';
        }

        return implode(' and ', $parts) . ' will be returned to the card you paid with.';
    }
}
